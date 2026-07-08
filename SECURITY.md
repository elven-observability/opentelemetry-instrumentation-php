# Security

Report security issues privately to Elven Observability maintainers.

Do not open public issues containing:

- tokens;
- passwords;
- private keys;
- raw customer payloads;
- production trace/log samples containing PII.

## Privacy Posture

By default this package:

- redacts sensitive headers and values;
- does not capture bodies;
- does not capture DB statements;
- drops unsafe metric labels.

Raw DB statements and raw attributes require explicit configuration and customer approval.

