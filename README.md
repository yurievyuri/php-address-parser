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

## Escalating to a provider

Rules generalise; the long tail does not. `EscalatingParser` runs the rules, inspects the result,
and consults the configured providers **only while something is still wrong** — so the paid path
stays rare.

```php
use Address\Parser\Config\ParserFactory;

$parser = (new ParserFactory(cache: $psr16, logger: $psr3))->create([
    'escalate_on' => ['country_missing', 'token_lost'],
    'providers' => [
        ['type' => 'libpostal', 'endpoint' => 'http://libpostal.internal:8080/parse'],
        ['type' => 'llm', 'model' => 'claude-opus-5'],
    ],
]);

$parser->parse($address);
```

Or from a file, with `symfony/yaml` or `ext-yaml` installed:

```php
$parser = (new ParserFactory())->createFromYaml(__DIR__ . '/address-parser.yaml');
```

See [`examples/address-parser.yaml`](examples/address-parser.yaml) for a documented configuration.

### Which issues can trigger escalation

`escalate_on` takes any of: `country_missing`, `token_lost`, `city_is_country`, `line1_empty`,
`postcode_missed`, `postcode_implausible`. Omit the key to escalate on any issue at all.

These are the per-address form of the same measures the rule engine is held to over the corpus,
which is what makes them a sound trigger: they fire on exactly the addresses the rules are known to
get wrong.

### Two safeguards worth knowing about

**A refinement is only accepted if it is better.** The result is re-inspected after every provider;
one that resolves the country but loses the street has made the address worse and is discarded.

**A provider is an improvement, never a dependency.** If it times out or throws, the rule-based
result is returned and the failure is logged. An address that parses by rules must not fail because
a remote service is down.

**The LLM may not invent.** Every word of a model's answer is checked against the input. A model
that supplies a postcode it "knows" for a street is writing plausible, wrong data into your
records — worse than an address that failed to parse — so such answers are rejected.

## Adding your own provider

Two ways, both of which keep your code out of this package.

Register a factory under a type name:

```php
$factory = (new ParserFactory())->register(
    'paf',
    fn (array $options) => new IdealPostcodesRefiner($options['api_key']),
);
```

…or name the class in the configuration:

```yaml
providers:
  - type: custom
    class: App\Address\IdealPostcodesRefiner
    options: { api_key: '%env(IDEAL_POSTCODES_KEY)%' }
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

### LLM providers

The built-in provider talks to Claude through the official PHP SDK (`composer require
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

Pass an instance as `['type' => 'llm', 'client' => $yourClient]` and the rest of the pipeline —
caching, the anti-invention check, the improvement check — works unchanged.

**Cache the answers.** Address strings repeat heavily in real data, and a PSR-16 cache passed to
`ParserFactory` means each distinct address is paid for once.

### libpostal

libpostal is a statistical parser trained on tens of millions of addresses. It cannot be loaded
into a PHP process — a C library plus gigabytes of models — so it runs as a sidecar and is reached
over HTTP. Point the provider at any service that returns libpostal's own label/value pairs.

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
