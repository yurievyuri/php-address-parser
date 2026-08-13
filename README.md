# address-parser

Splits free-form postal address strings into structured components — `line1`, `line2`, `city`,
`postcode`, `country` — for addresses that were typed by people rather than validated by a form.

```php
use Address\Parser\RuleBasedParser;

$address = (new RuleBasedParser())->parse('1 Harbour House Harbour Way Shoreham-By-Sea West Sussex BN43 5HZ');

$address->line1;       // "1 Harbour House Harbour Way Shoreham-By-Sea West Sussex"
$address->postcode;    // "BN435HZ"
$address->countryCode; // "GB" — inferred from the postcode's shape; the string never named a country
```

Rules first, and a provider only when the rules fall short: an LLM or a libpostal service is
consulted for the addresses that came out wrong, and paid for only there.

## Install

```bash
composer require yurievyuri/address-parser
```

PHP 8.2+, `ext-mbstring`. Everything else is optional and only needed by the provider you enable.

## What problem this solves

Addresses stored in a CRM are not form-validated. They arrive as `"street, city, postcode"` with no
country, as one comma-less run, with the postcode glued to the town, with a full stop at the end,
with the country repeated twice, with a placeholder `-` where a component should be. A parser that
assumes the last component is the country files the postcode as a country and resolves nothing.

The rules here were derived by measuring failures across **297 026 production address strings** and
fixing them by class rather than by example. Each rule exists because a specific class of address
was being mangled:

| Input shape | What naive splitting does | What this does |
|---|---|---|
| `15 Davies Street London, W1K 3DE` | postcode becomes the country | postcode found, `GB` inferred from its shape |
| `… Bromsgrove B603DJ GB` | town, postcode and country in one component | all three separated |
| `Rue Glesener 21, Luxembourg, 1631` | the town is deleted as a country | `LU` resolved, Luxembourg stays the city |
| `Newark, New Jersey` | last word matches Jersey → `JE` | no country — a bare word needs a postcode to vouch for it |
| `Flat 3, 45A, London` | `45A` taken for a postcode and removed | building number kept |
| `…, Manchester, UNITED KINGDOM, M2 3BD` | `UNITED KINGDOM` becomes the city | Manchester is the city |

The governing invariant is that **nothing is discarded**: every meaningful token of the input
survives in some output field. The parser redistributes an address, it never deletes part of it.
That single property catches an entire class of defects — a dropped house number, a vanished town,
a city truncated to "New" — without anyone having to think of the example first.

## Configuring the pipeline

`EscalatingParser` runs the rules, inspects the result, and consults the configured services **only
while something is still wrong** — so the paid path stays rare.

The pipeline knows three things about a service and no more: **where it sits in the order**,
**whether it is on**, and **an opaque bag of settings** that only that service understands. Whether
it is a language model, a geocoder, or a lookup against a national postal file is the service's own
business — the parser never branches on it.

```yaml
# address-parser.yaml
http:
  connect_timeout: 3    # seconds for the TCP + TLS handshake
  timeout: 10           # seconds for the whole exchange

escalate_on:
  - country_missing
  - token_lost

services:
  - service: libpostal
    enabled: true
    endpoint: '${LIBPOSTAL_URL:-http://libpostal.internal:8080/parse}'

  - service: claude
    enabled: true
    model: claude-opus-5
    api_key: '${ANTHROPIC_API_KEY}'
    # aws_region: '${AWS_REGION}'   # go through Bedrock instead of the Anthropic API
```

```php
use Address\Parser\Config\ParserFactory;

$parser = (new ParserFactory(cache: $psr16, logger: $psr3))
    ->createFromFile(__DIR__ . '/address-parser.yaml');

$parser->parse($address);
```

`.yaml`, `.json`, and `.php` files all work, and `create()` takes the same structure as a PHP array
when configuration comes from somewhere else entirely. See
[`examples/address-parser.yaml`](examples/address-parser.yaml) for a documented file.

### Secrets

**Any value may reference an environment variable** — `${ANTHROPIC_API_KEY}`, or
`${LIBPOSTAL_URL:-http://localhost:8080/parse}` with a fallback. References resolve when the
configuration loads, and one that is unset with no fallback fails **there**, with the variable
named, rather than as a puzzling HTTP failure later.

So the file carries structure — order, on/off, endpoints, model names, limits — and is safe to
commit; keys stay in the environment, wherever your deployment gets them from.

### Which issues can trigger escalation

`escalate_on` takes any of: `country_missing`, `token_lost`, `city_is_country`, `line1_empty`,
`postcode_missed`, `postcode_implausible`. Omit the key to escalate on any issue at all.

These are the per-address form of the same measures the rule engine is held to over the corpus,
which is what makes them a sound trigger: they fire on exactly the addresses the rules get wrong.

### Timeouts

A parser is called from a request path, so no service may hang it. The defaults are **3 seconds to
connect and 10 seconds for the exchange**, set per file under `http:` and overridable per service.

PSR-18 deliberately says nothing about timeouts — they are constructor configuration, different in
every implementation — so the library constructs the HTTP client itself to enforce them. Pass your
own client and its configuration wins, including its timeouts.

> The Claude SDK has its own timeout, and its default is generous (minutes). If you run the LLM
> service on a latency-sensitive path, construct the SDK client with a timeout you chose and pass
> it in as `client`.

### Two safeguards worth knowing about

**A refinement is only accepted if it is better.** The result is re-inspected after every service;
one that resolves the country but loses the street has made the address worse and is discarded.

**A service is an improvement, never a dependency.** If it times out or throws, the rule-based
result is returned and the failure is logged. An address that parses by rules must not fail because
a remote service is down.

**The LLM may not invent.** Every word of a model's answer is checked against the input. A model
that supplies a postcode it "knows" for a street is writing plausible, wrong data into your
records — worse than an address that failed to parse — so such answers are rejected.

## Adding your own service

Name the class in the configuration — nothing to register:

```yaml
services:
  - service: paf
    class: App\Address\IdealPostcodesRefiner
    api_key: '${IDEAL_POSTCODES_KEY}'
```

Remaining keys are passed to the constructor as named arguments, or to a static `fromConfig()` if
the class defines one. Alternatively register a factory under a name:

```php
$factory = (new ParserFactory())->register(
    'paf',
    fn (array $settings) => new IdealPostcodesRefiner($settings['api_key']),
);
```

Either way the class implements `RefinerInterface`:

```php
interface RefinerInterface
{
    public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress;
    public function name(): string;
}
```

It receives the original string *and* the draft the rules produced, so it can correct one field
without re-deriving the rest.

### LLM services

The built-in `claude` service talks to Claude through the official PHP SDK (`composer require
anthropic-ai/sdk`), on the Anthropic API or on Bedrock — add `aws_region` for the latter and the
`anthropic.` model prefix is applied for you. Structured output is requested via a JSON schema, so
the answer is schema-valid rather than JSON-if-we-are-lucky.

Any other vendor plugs in behind `LlmClientInterface` — one method, no SDK assumptions:

```php
interface LlmClientInterface
{
    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array;
    public function describe(): string;
}
```

Pass an instance as the service's `client` setting and the rest of the pipeline — caching, the
anti-invention check, the improvement check — works unchanged.

**Cache the answers.** Address strings repeat heavily in real data, and a PSR-16 cache passed to
`ParserFactory` means each distinct address is paid for once.

### libpostal

libpostal is a statistical parser trained on tens of millions of addresses. It cannot be loaded
into a PHP process — a C library plus gigabytes of models — so it runs as a sidecar and is reached
over HTTP. Point the service at anything that returns libpostal's own label/value pairs.

### HTTP

Requests go through **PSR-18**, and the client is discovered from what your project already has
(Guzzle, Symfony HttpClient, anything else). That means your existing middleware applies —
tracing, retries, proxies, corporate CA bundles — and tests mock a client instead of a socket.

## The country table

Country resolution sits behind `CountryResolverInterface`, so it can be swapped for the host
application's own table. The bundled resolver matches ISO 3166-1 names, alpha-2 and alpha-3 codes,
and the aliases real addresses carry — `England`, `Great Britain`, `USA`, `Holland`, `Eire`.

## Legacy integration

`ParsedAddress::toLegacyArray()` returns `['line1', 'line2', 'city', 'postcode', 'country',
'country_code']`, so a project replacing an older function can keep its call sites unchanged:

```php
public static function parseAddressFormat(string $address, bool $spaceInPostCode = false): array
{
    return self::parser()->parse($address, $spaceInPostCode)->toLegacyArray();
}
```

## Why results are explainable

Every result carries a `trace` recording which rule filled which field, and `issues` listing what
the inspector found wrong. "It parsed my address wrongly" is answerable by reading the result
rather than by re-deriving the parse.

```php
$result->trace;  // ['country' => 'inferred from the UK postcode', 'city' => 'last component', …]
$result->issues; // ['country_missing']
$result->source; // 'rules' | 'libpostal' | 'llm' | your provider's name
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

The specification is the test suite: every case in `RuleBasedParserSpecTest` is a real production
address, and every assertion records a defect that reached users. It includes a property-based pass
over a 406-address corpus asserting the never-discard invariant.

## Licence

MIT.
