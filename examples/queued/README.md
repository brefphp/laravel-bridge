# Queued Laravel using Lift constructs

This config skeleton deploys a solid foundation for your Serverless Laravel applications using https://github.com/getlift/lift for the SQS queue construct.

## Required plugins:
Install the required Serverless plugins (Lift and Scriptable):
 - serverless plugin install -n serverless-lift
 - serverless plugin install -n serverless-scriptable-plugin

Lift give us easy access to AWS CDK constructs that help us build out best practice components (in this example, our SQS queue). If you want to learn more about Lift, check out their documentation here:
https://www.serverless.com/plugins/lift

The Scritable plugin provides us with deployment hooks to help ensure we run any pending Laravel Migrations at the point of deployment. Documentation can be found here:
https://www.serverless.com/plugins/serverless-scriptable-plugin 

## Laravel Octane support (Optional)
Simply swap the commented blocks in the example serverless.yml file provided to start using Laravel Octane