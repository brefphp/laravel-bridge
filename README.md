# Bref Laravel Bridge

A better Laravel integration for Bref, including Octane support.

## TODOs

- [ ] Publish runtime command
- [ ] Finish readme
- [ ] Publish to Packagist
- [ ] Support persistent PostgreSQL connections

## Installation

...

## Configuration

```yml
provider:
  environment:
    AWS_ACCOUNT_ID: ${aws:accountId}
    APP_SSM_PREFIX: /example-${sls:stage}/
    APP_SSM_PARAMETERS: "APP_KEY, DATABASE_URL"

plugins:
  - ./vendor/bref/bref  
```

### Web

```yml
functions:
  web:
    handler: php/runtime.php
    environment:
      BREF_BINARY_RESPONSES: 1 # optional
    layers:
      - ${bref:layer.php-81}
    events:
      - httpApi: '*'
```

### Octane
```yml
functions:
  web:
    handler: php/runtime.php
    environment:
      APP_RUNTIME: octane
      BREF_LOOP_MAX: 250
      BREF_BINARY_RESPONSES: 1 # optional
    layers:
      - ${bref:layer.php-81}
    events:
      - httpApi: '*'
```

### Queue

```yml
functions:
  queue:
    handler: php/runtime.php
    timeout: 59
    environment:
      APP_RUNTIME: queue
    layers:
      - ${bref:layer.php-81}
    events:
      - sqs:
          arn: !GetAtt Queue.Arn
          batchSize: 1
          maximumBatchingWindow: 60
```

### Console

```yml
functions:
  cli:
    handler: php/runtime.php
    timeout: 720
    environment:
      APP_RUNTIME: cli
    layers:
      - ${bref:layer.php-81}
      - ${bref:layer.console}
    events:
      - schedule:
          rate: rate(1 minute)
          input: '"schedule:run"'
```
