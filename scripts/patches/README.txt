Patches to drupal.org modules, applied at composer install time
(composer.json "extra": {"patches-file": "patches.json"} until they land
upstream; see DECISIONS for how the upstream MRs go).

0001-feat-backend-SubQ-Snap-speaks-System-One.patch
  ai_provider_universal (1.0.x): the `subq` backend, for SubQ Snap's
  Decisions API. The same wire as TypeSafe (Jev): /v1/systemone, typed
  answers with calibrated confidence, billed input only. Its own base URI,
  Snap's list price ($0.04/1M input, output free), and a model filter that
  keeps Savant (a text model) off a decision server. The judge
  (scripts/judge-similar.php) and any decision job call it through
  UniversalProvider::decide(); a route can hold Jev and Snap as candidates.
  Apply: git apply in the module's checkout, or composer patches.
