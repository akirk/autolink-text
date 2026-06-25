# Autolink Text

Autolink Text is a WordPress/Gutenberg prototype that uses the same PHP-only bot approach as Shouter to participate in Gutenberg's RTC sync stream.

It watches completed paragraph events and replaces the first configured matching term with an anchor tag. Configured text color, background color, and bold settings are applied to the generated link. The initial example setting links `Playground` to `https://playground.wordpress.net/`.

The plugin has a settings page at `Settings -> Autolink Text` where a WordPress user can be selected as the bot identity and linked terms can be configured in a table with term, URL, text color, background color, and bold fields. The color fields use WordPress' built-in color picker.

[Open Autolink Text in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/auto-linker/refs/heads/main/blueprint.json).

The main plugin entry file is `autolink-text.php`.

The current implementation is intentionally narrow. It handles top-level paragraph completion, plain paragraph content, and the subset of Gutenberg/Yjs updateV2 payloads needed to replace a matched text range. It does not enqueue editor JavaScript and does not expose a separate public mutation endpoint.

## Composer and Dist Branches

`composer.json` points Composer at the upstream GitHub repository for `maxschmeling/y-php`:

```sh
composer install
```

The repository ignores `vendor/`; install Composer dependencies locally before running the plugin or tests.
