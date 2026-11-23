---
view: components.home.faq
badge: FAQ
title: "{package.name}"
highlight: Questions
subtitle: "Quick answers about installing and using {package.name}."
---

## What is {package.name}?

{card.description} It's a free, open-source {technology.name} package by Pharaonic.

## How do I install {package.name}?

Run `composer require {package.composer}` in your project's root directory. For the default database store, publish the migration with `php artisan vendor:publish --tag=api-idempotency-migrations` and run `php artisan migrate`.

## What does {package.name} require?

The latest release requires {package.requiresText}.

## Does it guarantee exactly-once execution?

No package can. It guarantees that a key is never reserved twice at the same time and that an uncertain outcome is never run again automatically. For payments, also use a unique business reference and your payment provider's own idempotency key.

## Database or Redis?

Both reserve keys atomically. The database store is the default and needs only the migration; Redis expires records by itself and suits high traffic. Switch with `IDEMPOTENCY_STORE=redis`.

## What happens when a client reuses a key with a different body?

The request is refused with `409` and the code `idempotency_key_conflict`, and your route does not run.

## Is {package.name} free to use?

Yes. {package.name} is open source under the {package.license} license, so you can use it in personal and commercial projects.

## Where can I find the {package.name} documentation?

Read the [{package.name} documentation]({package.docsUrl}) for setup, configuration, and usage examples.

## How do I report a bug or contribute to {package.name}?

Open an issue or a pull request on [GitHub]({package.githubUrl}), ask in [GitHub Discussions](https://github.com/orgs/Pharaonic/discussions), or chat with us on [Discord](https://discord.gg/XQG9RhvEvf).
