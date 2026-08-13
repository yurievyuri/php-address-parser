# address-parser

Splits free-form postal address strings into structured components — `line1`, `line2`, `city`,
`postcode`, `country` — for addresses that were typed by people rather than validated by a form.

```php
use Codelot\AddressParser\RuleBasedParser;

$address = (new RuleBasedParser())->parse('1 Harbour House Harbour Way Shoreham-By-Sea West Sussex BN43 5HZ');

$address->line1;       // "1 Harbour House Harbour Way Shoreham-By-Sea West Sussex"
$address->postcode;    // "BN435HZ"
$address->countryCode; // "GB" — inferred from the postcode's shape; the string never named a country
```

Rules first, and a provider only when the rules fall short: an LLM or a libpostal service is
consulted for the addresses that came out wrong, and paid for only there.

## Install

```bash
composer require codelot/address-parser
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

```php
// address-parser.php — the recommended format: no extra package, opcached, and values can come
// from wherever the application already keeps them (AppConfig, a parameter store, env).
return [
    'http' => [
        'connect_timeout' => 3,   // seconds for the TCP + TLS handshake
        'timeout' => 10,          // seconds for the whole exchange
    ],

    'escalate_on' => ['country_missing', 'token_lost'],

    'services' => [
        [
            'service' => 'libpostal',
            'enabled' => true,
            'endpoint' => getenv('LIBPOSTAL_URL') ?: 'http://libpostal.internal:8080/parse',
        ],
        [
            'service' => 'claude',
            'enabled' => true,
            'model' => 'claude-haiku-4-5',
            'api_key' => getenv('ANTHROPIC_API_KEY') ?: null,
            // 'aws_region' => getenv('AWS_REGION'),  // go through Bedrock instead
        ],
    ],
];
```

`create()` takes the same structure as a plain array when configuration comes from somewhere else
entirely. `.php`, `.yaml`, and `.json` files all work — see
[`examples/address-parser.php`](examples/address-parser.php) and
[`examples/address-parser.yaml`](examples/address-parser.yaml).

### Which format

**Prefer PHP.** It needs no extra package, it is opcached like the rest of your code, and a value
that lives in AWS AppConfig, a parameter store, or a container parameter needs no bridge — read it
where you build the array. Conditional logic (`'enabled' => $flags->has('address_llm')`) is just
code.

YAML is there when a file has to be readable or editable by people who do not write PHP. It needs
`symfony/yaml` or `ext-yaml`, and in a YAML or JSON file any value may instead reference an
environment variable — `${ANTHROPIC_API_KEY}`, or `${LIBPOSTAL_URL:-http://localhost:8080/parse}`
with a fallback. References resolve at load time, and one that is unset with no fallback fails
**there**, naming the variable, rather than as a puzzling HTTP failure later.

Either way the file carries structure — order, on/off, endpoints, model names, limits — and is safe
to commit; keys stay in the environment.

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

**And it may not guess a country.** The country is the one field that cannot be checked that way —
a code is never a substring of the address. So the model must also return `country_evidence`: the
words it read the country from, quoted from the input. If those words are not in the address, the
country is dropped and the reason is logged.

This is not theoretical. Measured on 150 unresolved production addresses, requiring the evidence
cut one model's "improvements" from 14 to 7 — the other half were guesses like
`2 Addison Avenue Kj Food & Wine → US`, where the street exists in London too. A wrong country
flows into compliance checks and payment instructions; an empty one does not.

Set `reject_invented_text: false` or `require_country_evidence: false` to disable either guard.

## Logging and error collection

Two things, both configured in the same block.

**A PSR-3 logger.** Pass your own to `ParserFactory` and the library uses it. Give a path instead
and it writes one JSON object per line to that file — enough to answer "why did this address parse
like that" with `grep`, without making Monolog a prerequisite. Logging failures are swallowed: a
full disk loses a log line, it does not fail an address parse.

**An in-memory collector.** A log file answers questions afterwards; this answers them now. It
decorates the logger, keeps events at or above a severity you choose, and lets the caller ask what
happened after a batch — then hand the serious part to the rest of the system.

```php
'logging' => [
    'path' => '/home/bitrix/debug/address/parser.log',
    'level' => 'info',
    'collect' => [
        'min_level' => 'warning',
        'notify_level' => 'critical',
        'notifier' => App\Address\SlackNotifier::class,   // implements NotifierInterface
    ],
],
```

```php
$factory = new ParserFactory();
$parser = $factory->createFromFile(__DIR__ . '/address-parser.php');

foreach ($addresses as $address) {
    $parsed = $parser->parse($address);
}

$events = $factory->events();

if ($events?->hasProblems()) {
    report($events->toArray('error'));          // everything at error and above, as arrays
}

foreach ($events?->criticalRecords() ?? [] as $record) {
    alert($record->message, $record->context);  // the subset worth waking someone for
}
```

Severity is used deliberately, not decoratively:

| Level | Raised when |
|---|---|
| `debug` | every parse, with the trace of which rule filled which field |
| `info` | an address was refined by a service (which one, what it resolved) — the line that answers "how often are we paying for escalation", or a refinement was rejected for not improving the result |
| `warning` | a model's answer contained text that was not in the input |
| `error` | one service failed — the others still ran |
| `critical` | **every** service failed: escalation is down and addresses are silently degrading to rule-based results |

That last row is the one worth wiring to an alert. A single provider erroring is an incident for
the log; all of them erroring means the pipeline is quietly running at reduced quality, which is
exactly the failure nobody notices until a report looks wrong weeks later.

## Facades

Two entry points for the two ways this gets used.

### Replacing a legacy static function

When the old parser is called from many places at once and none of them can change, the old
function becomes one line:

```php
public static function parseAddressFormat($address = '', bool $spaceInPostCode = false): array
{
    return LegacyArrayParser::parse($address, $spaceInPostCode);
}
```

Wire the real parser in once, during setup:

```php
LegacyArrayParser::configure($parser, $logger);
// or straight from a file:
LegacyArrayParser::configureFromFile(__DIR__ . '/address-parser.php', $logger);
```

Two properties make that a one-step change:

**It never throws.** Legacy call sites sit inside event handlers and business processes with no
error handling, where an exception does not read as a parsing problem — it stops a record from
saving. A failure of the configured pipeline falls back to the rule engine, and a failure of that
returns an empty result of the right shape.

**It accepts what the untyped signature accepted** — `null`, numbers, `Stringable`, and even an
already-parsed array, which is reassembled rather than mangled by a second parse.

`parseToObject()` is there for the call sites that can use more than six keys — the trace, the
issues, which service answered.

### Running over many addresses

```php
$summary = (new BatchParser($parser, $logger))->run($addresses);

$summary->total;         // 449719
$summary->escalated();   // how many were answered by a paid service
$summary->byIssue;       // what is still wrong, by kind
$summary->toArray();     // ready for a report, a log line, or an alert
```

One bad row cannot end a run of a hundred thousand — failures are logged, counted, and skipped.
`map()` does the same lazily and yields results one at a time, so an export need not fit in memory.

The batch inspects every result itself rather than trusting the `issues` field, because only the
escalating parser fills that in — otherwise a run over the bare rule engine would report a clean
sweep over addresses it never examined.

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

**Everything the model is given is configuration**: the prompts, the JSON Schema its answer must
satisfy (`schema`, or `schema_file` pointing at a `.json`), and `field_map` — which answer key fills
which result field, so a custom schema needs no code change. All three are part of the cache key.

**The prompts.** `system_prompt` and `user_prompt` (or `system_prompt_file` /
`user_prompt_file`, easier to review as their own files) override the built-in ones per service;
the user prompt takes `{address}`, `{draft}`, and `{issues}`. Prompts are part of the cache key, so
editing one never returns answers produced by the previous version.

The built-in prompt weighs country evidence in a deliberate order — an explicit country name first,
then a city or district, then the language and naming of the address, and **the postcode last**.
Postcode formats collide across countries (five digits are German, French, Spanish and American),
so a postcode confirms what stronger evidence already says and never outvotes it.

**Retries.** A `429` or a `5xx` is retried up to three times, honouring `Retry-After` when the
provider sends one and backing off exponentially when it does not. Rate limits on shared tiers are
common enough that failing on the first one wastes the call.

**Cache the answers.** Address strings repeat heavily in real data, and a PSR-16 cache passed to
`ParserFactory` means each distinct address is paid for once.

### Bedrock

`service: bedrock` goes through the **Converse API** — one request shape for every vendor on the
platform, so moving between Claude, Nova, Mistral, Qwen and the OSS models is a `modelId` in
configuration rather than a new adapter. Verified against all five: the same request returns the
same `toolUse` block from each.

```php
[
    'service' => 'bedrock',
    'model' => 'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
    'effort' => false,                    // false or absent = do not send the parameter
    'model_fields' => [],                 // vendor-specific extras, passed through untouched
    // 'api' => 'invoke',                 // the older InvokeModel path, for a model Converse omits
]
```

It uses `aws/aws-sdk-php`, which a project on AWS already has: credentials come from the instance
role and the traffic stays inside the account. The SDK is optional — the client only asks for it
when this service is enabled.

Four things worth knowing, all learned against the live API:

**On-demand invocation needs an inference profile, not a bare model id.** `anthropic.claude-…`
answers *"Invocation … with on-demand throughput isn't supported"*; the regional profile
(`eu.anthropic.claude-…`) works. Ids verified against a live account, 2026-08-13:

| Vendor | Model id |
|---|---|
| Anthropic | `eu.anthropic.claude-haiku-4-5-20251001-v1:0` — the default, $1/$5 per MTok, does not take `effort`; `eu.anthropic.claude-sonnet-5` — $3/$15, worth it only with `'effort' => 'low'` |
| Amazon | `eu.amazon.nova-micro-v1:0`, `eu.amazon.nova-lite-v1:0`, `eu.amazon.nova-pro-v1:0` |
| Mistral | `eu.mistral.pixtral-large-2502-v1:0`, `mistral.ministral-3-3b-instruct` |
| Alibaba | `qwen.qwen3-32b-v1:0` |
| OpenAI (OSS) | `openai.gpt-oss-120b-1:0` |

Models without a regional profile are invoked by their plain id, as in the last three rows.

**Vendor-specific parameters live in `model_fields`**, not in code, because what belongs there
differs per model. Claude Haiku rejects `output_config.effort` outright — *"Extra inputs are not
permitted"* — while Sonnet accepts it.

**Capability is discovered, not assumed.** If a model refuses the vendor fields, the client retries
without them; if it cannot force a tool, without `toolChoice`; if it has no tools at all, by asking
for JSON in the prompt. Each step is logged. The refusal is recognised by whether the error names a
field *we sent* — vendors word it differently ("Extra inputs are not permitted" vs "extraneous key
[x] is not permitted"), so matching phrases would be brittle.

**`effort` has no "off" value.** `false` or omitting the key leaves the parameter out entirely,
which is what a model that does not support it requires.

**Start at the cheap end.** Splitting an address is extraction against a fixed schema, not
reasoning, and it runs on every address the rules could not resolve — so the model choice is a cost
decision made per thousand addresses. Haiku first; if acceptance says it is not enough, Sonnet at
`'effort' => 'low'` before anything larger. Measured on 40 unresolved production addresses, Haiku
came to **$1.87 per 1000**.

### libpostal### libpostal

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
