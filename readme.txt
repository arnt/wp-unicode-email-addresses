=== Unicode Email Addresses ===
Contributors: agulbra
Tags: email, unicode, idn, internationalization, sanitization
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accepts Unicode email addresses such as info@grå.org, and stops sanitize_email() from rewriting an address into a different address.

== Description ==

WordPress rejects `info@grå.org`, and it rejects `阿Q@例子.中国` and
`उदाहरण@उदाहरण.भारत` for the same reason: `is_email()` allows nothing
but ASCII letters, digits and a little punctuation. Mail has allowed
more than that since RFC6530-3 in 2012 and services such as gmail and
o365 can send to them.

This plugin replaces the validation in `is_email()` and
`sanitize_email()` with a version that accepts them. It hooks the
`is_email` and `sanitize_email` filters, so no Core file is touched,
and deactivating the plugin restores the old behaviour exactly.

There is a second change. The Core `sanitize_email()` does not just
clean an address up, it rewrites: One address can go in and a
different, deliverable address can come out. For info@grå.org, Core
produces either info@gr.org or info@gra.org depending on what keyboard
was used originally.

This plugin does not do that. It repairs the mistakes that come from
copying an address out of running text (surrounding whitespace, a
trailing dot at the end of a sentence, that kind of thing). If what
remains isn't a valid address, this plugin returns an empty string.

Punycode domains are decoded, so `arnt@xn--gr-zia.org` validates and
comes back as `arnt@grå.org`. Decoding needs the `intl` extension;
without it, addresses written in punycode are rejected rather than
guessed at.

Although agulbra is listed as sole contributor to this plugin,
practically all of the code was written earlier, for Core, mostly by
agulbra and dmsnell with review and contributions from others. See
credits below.

= What is accepted =

The addresses a user can type into the major browsers of 2026, extended with the
Unicode forms of RFC6530-3. A few addresses that RFC5322 permits are rejected,
because a string like`"<iframe src=...>"@example.com` is far more likely to be
an attack than somebody's mailbox.

= A note on databases =

The plugin accepts Unicode addresses regardless of what the database can store.
`utf8mb4` has been the default since 2006, so the database is highly likely
to be able to store unicode.

If your site is on `latin1` or similar, MySQL may mangle or truncate
addresses, and you should convert it to `utf8mb4` before installing
this plugin. If it is three-byte `utf8`, everything will work for the
time being, but conversion to `utf8mb4` is advisable (and not urgent).

== Installation ==

1. Install the plugin through Plugins → Add New, or upload the folder to `/wp-content/plugins/`.
2. Activate it through the Plugins menu.

There is nothing to configure and no settings page. Validation changes the
moment the plugin is active.

== Frequently Asked Questions ==

= Can WordPress actually send mail to these addresses? =

`wp_mail()` will accept them as recipients. Since WordPress 6.9
PHPMailer can send mail to these addresses.  Your upstream server
needs support for the SMTPUTF8 extension. Postfix and Exim added
support for that almost ten years ago, so it's quite likely that your
server has the support.

If you use a plugin to send mail, that plugin may or not have support
for unicode.

You can test it with a message to grå@grå.org. You'll either get an
error or an autoresponse.

= Does it change addresses already in the database? =

It does not rewrite any stored row. Reading a row is unaffected too:
an address fetched raw, as `get_userdata()` and `get_comment()` do, is
returned exactly as stored, with no validation.

= Will other plugins that hook these filters still work? =

Mostly. This plugin answers from scratch at priority 10 and ignores
the verdict Core reached, so a filter that ran before it at a lower
priority is overridden.  A filter at a higher priority still has the
last word. Filters that switch on the context string
(`local_invalid_chars` etc) see the contexts Core produces, unchanged.

= Can I keep the old ASCII-only behaviour? =

Deactivate the plugin. It has no partial mode.

If you deactivate the plugin, WordPress will most likely still be able
to send mail to addresses in the database. Plugins can affect this,
however.

== Changelog ==

= 1.0.0 =
* First release. Unicode validation in `is_email()` and `sanitize_email()`, punycode decoding, and a `sanitize_email()` that repairs rather than rewrites.

== Credits ==

The validation code began as the patch in
https://core.trac.wordpress.org/ticket/31992, developed in
https://github.com/WordPress/wordpress-develop/pull/5237 and briefly
committed to Core. Props agulbra, akirk, benniledl, dmsnell,
ironprogrammer, justlevine, mdawaffe, mukeshpanchal27, SirLouen and
tusharbharti.
