# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Rule-based parser for free-form postal addresses, with rules derived from measuring failures
  across 297 026 production address strings.
- Country resolution behind an interface, with a bundled ISO 3166-1 table and the aliases real
  addresses carry (`England`, `Great Britain`, `USA`, `Holland`, `Eire`).
- Quality inspection: six per-address measures matching those the rule engine is held to over the
  corpus.
- `EscalatingParser` — consults providers only for addresses that came out wrong, accepts a
  refinement only when it is measurably better, and never lets a provider failure break a parse.
- Providers: an LLM refiner (Claude via the official PHP SDK, on the API or Bedrock; any other
  vendor behind `LlmClientInterface`) and a libpostal HTTP client.
- `ParserFactory` — pipeline configuration from a PHP array or a YAML file, with custom providers
  registered by name or by class.
- Behavioural specification of 68 test cases built from real production addresses, including a
  property-based pass asserting that no part of an address is ever discarded.
