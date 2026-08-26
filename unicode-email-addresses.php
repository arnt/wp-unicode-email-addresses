<?php
/**
 * Plugin Name:       Unicode Email Addresses
 * Plugin URI:        https://github.com/arnt/wp-unicode-email-addresses
 * Description:       Accepts Unicode (RFC6530-3) email addresses in is_email() and sanitize_email(), and makes sanitize_email() stop rewriting addresses into different addresses.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Arnt Gulbrandsen
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * The validation code is adapted from the WP_Email_Address class briefly in core,
 * developed in https://github.com/WordPress/wordpress-develop/pull/5237 and then
 * further improved by dmsnell. It would be fair to said that Arnt and Dennis wrote
 * the good code together, Dennis wrote the excellent documentation alone while Arnt
 * typoed the bugs.
 *
 * Props  agulbra, akirk, benniledl, dmsnell, ironprogrammer, justlevine,
 * mdawaffe, mukeshpanchal27, SirLouen and tusharbharti.
 *
 */

/**
 * A validated email address. The address may or may not be deliverable.
 *
 * Use the static factory method {@see Unicode_Email_Address::from_string()} to create
 * instances of this class rather than the constructor. It returns an instance only for
 * addresses that validate, and null otherwise.
 *
 * Example:
 *
 *     $email = Unicode_Email_Address::from_string( 'info@grå.org' );
 *     'info'    === $email->get_localpart();
 *     'grå.org' === $email->get_unicode_domain();
 *
 * @see self::from_string()        to parse and validate a provided email address.
 * @see self::get_localpart()      for the local part or mailbox of the address.
 * @see self::get_ascii_domain()   for the A-label (punycode) form, which belongs only in
 *                                 the paths that cannot take anything else: DNS lookups,
 *                                 and delivery to a server without SMTPUTF8.
 * @see self::get_unicode_domain() for the U-label form, which belongs everywhere a human
 *                                 reads the domain, links included.
 */
final class Unicode_Email_Address {
	/**
	 * Regex for the local part.
	 *
	 * Letters, numbers and the usual mail punctuation, in grapheme clusters:
	 * each cluster opens with a non-combining character, then any combining
	 * marks that belong to it.
	 *
	 * @var string
	 */
	const LOCAL_PART_REGEX = '/^([\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]\p{M}*)+$/u';

	/**
	 * Pattern for a single domain label (no dot).
	 *
	 * A label starts and ends with a letter or digit and may contain hyphens
	 * in between, with the same grapheme-cluster structure as the local part:
	 * each cluster opens with a letter or digit, not a combining mark.
	 *
	 * @var string
	 */
	const DOMAIN_LABEL = '[\p{L}\p{N}]\p{M}*(?:(?:[\p{L}\p{N}-]\p{M}*)*[\p{L}\p{N}]\p{M}*)?';

	/**
	 * Regex for the domain.
	 *
	 * Assembled from {@see self::DOMAIN_LABEL}: one label, then zero or more
	 * dot-prefixed labels.
	 *
	 * @var string
	 */
	const DOMAIN_REGEX = '/^' . self::DOMAIN_LABEL . '(?:\.' . self::DOMAIN_LABEL . ')*$/u';

	/**
	 * The local part of the email address (the portion before the '@').
	 *
	 * @var string
	 */
	private $localpart;

	/**
	 * The email domain using punycode transcription instead of Unicode characters.
	 *
	 * Example:
	 *
	 *     $email = Unicode_Email_Address::from_string( 'info@grå.org' );
	 *     'xn--gr-zia.org' === $email->get_ascii_domain();
	 *
	 * @see self::$decoded_domain
	 *
	 * @var string
	 */
	private $encoded_domain;

	/**
	 * The email domain, which may contain Unicode characters.
	 *
	 * Example:
	 *
	 *     $email = Unicode_Email_Address::from_string( 'info@xn--gr-zia.org' );
	 *     'grå.org' === $email->get_unicode_domain();
	 *
	 * @see self::$encoded_domain
	 *
	 * @var string
	 */
	private $decoded_domain;

	/**
	 * Private constructor. Use {@see Unicode_Email_Address::from_string()} to create instances.
	 *
	 * @private
	 *
	 * @param string      $localpart      The local part of the email address.
	 * @param string      $ascii_domain   The domain part, which may include punycode transcription.
	 * @param string|null $unicode_domain The domain part, which may contain Unicode characters, or
	 *                                    null if no Unicode translation exists.
	 */
	private function __construct( string $localpart, string $ascii_domain, ?string $unicode_domain ) {
		$this->localpart      = $localpart;
		$this->encoded_domain = $ascii_domain;
		$this->decoded_domain = $unicode_domain;
	}

	/**
	 * Creates a Unicode_Email_Address from a string.
	 *
	 * This accepts the addresses a user can type into the major browsers of 2026,
	 * extended with the Unicode address forms of RFC6530-3, and rejects strings that
	 * are more likely to be typos, mispastes, or attacks. A few addresses that are
	 * valid according to RFC5322 are rejected — quoted local parts, for instance.
	 *
	 * Example:
	 *
	 *     // Typical all-US-ASCII email address.
	 *     $email = Unicode_Email_Address::from_string( 'webmaster@example.com' );
	 *     'webmaster'   === $email->get_localpart();
	 *     'example.com' === $email->get_ascii_domain();
	 *     'example.com' === $email->get_unicode_domain();
	 *
	 *     // Punycode domains are always decoded.
	 *     $email = Unicode_Email_Address::from_string( 'arnt@xn--gr-zia.org' );
	 *     'arnt'           === $email->get_localpart();
	 *     'xn--gr-zia.org' === $email->get_ascii_domain();
	 *     'grå.org'        === $email->get_unicode_domain();
	 *
	 *     // Unicode local parts are accepted.
	 *     $email = Unicode_Email_Address::from_string( 'gøril@example.com' );
	 *     'gøril' === $email->get_localpart();
	 *
	 *     // Some valid addresses (according to RFC5322) are rejected.
	 *     null === Unicode_Email_Address::from_string( '"<iframe src=...>"@example.com' );
	 *
	 * Note! If an address contains punycode encodings but the required {@see idn_to_utf8()}
	 * function is missing (from the `intl` extension), this will reject that email address.
	 *
	 * @param string $input The email address string to parse.
	 * @return Unicode_Email_Address|null An instance, or null if the input fails to validate.
	 */
	public static function from_string( string $input ): ?Unicode_Email_Address {
		// There must be exactly one '@' sign.
		$at_pos = strpos( $input, '@' );
		if ( false === $at_pos || strrpos( $input, '@' ) !== $at_pos ) {
			return null;
		}

		$localpart     = substr( $input, 0, $at_pos );
		$ascii_domain  = substr( $input, $at_pos + 1 );
		$domain_labels = explode( '.', $ascii_domain );

		foreach ( $domain_labels as $label ) {
			// DNS limits each label to 63 octets.
			if ( strlen( $label ) > 63 ) {
				return null;
			}
		}

		/*
		 * Without support for decoding punycode it's not possible to validate
		 * the email address, so abort if any domain labels require decoding.
		 *
		 * The pattern detects `xn--` prefixes and invalid ACE prefixes.
		 */
		$needs_decoding = 1 === preg_match( '/(?:^|\.)..--/', $ascii_domain );
		if ( $needs_decoding && ! function_exists( 'idn_to_utf8' ) ) {
			return null;
		}

		/*
		 * Validate each domain label, decode any punycode to UTF-8, and
		 * reassemble the decoded labels into the local $domain variable.
		 */
		if ( $needs_decoding ) {
			$decoded_labels = array();
			foreach ( $domain_labels as $label ) {
				// Decode punycode labels to their Unicode form for further validation.
				if ( str_starts_with( $label, 'xn--' ) ) {
					$label = idn_to_utf8( $label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
					if ( false === $label ) {
						return null;
					}
				} elseif ( 1 === preg_match( '/^..--/', $label ) ) {
					// Reject labels with a reserved ACE-like prefix (two chars followed by '--').
					return null;
				}
				$decoded_labels[] = $label;
			}
			$decoded_domain = implode( '.', $decoded_labels );
		} else {
			$decoded_domain = $ascii_domain;
		}

		// All parts must be valid UTF-8. (A valid ASCII string is also valid UTF-8.)
		if (
			! unicode_email_is_valid_utf8( $localpart ) ||
			! unicode_email_is_valid_utf8( $ascii_domain ) ||
			! unicode_email_is_valid_utf8( $decoded_domain )
		) {
			return null;
		}

		// Validate the local part against the allowed character set.
		if ( 1 !== preg_match( self::LOCAL_PART_REGEX, $localpart ) ) {
			return null;
		}

		// The domain must contain at least one dot.
		if ( ! str_contains( $ascii_domain, '.' ) ) {
			return null;
		}

		// Validate the domain against the allowed structure.
		if ( 1 !== preg_match( self::DOMAIN_REGEX, $decoded_domain ) ) {
			return null;
		}

		return new self( $localpart, $ascii_domain, $decoded_domain );
	}

	/**
	 * Returns the local part of the email address (the portion before the '@').
	 *
	 * @return string The local part of the email address.
	 */
	public function get_localpart(): string {
		return $this->localpart;
	}

	/**
	 * Returns the A-label (punycode) form of the domain, for the paths that accept
	 * nothing else: a DNS or MX lookup, an SMTP envelope to a server that has not
	 * announced SMTPUTF8, or a comparison against addresses that older code stored
	 * in ASCII form.
	 *
	 * Not for links, and not for anything else a human reads. A `mailto:` URI is
	 * RFC6068, which percent-encodes UTF-8 rather than transcribing to punycode,
	 * and the mail client behind the link wants the address that goes on the wire.
	 *
	 * Note! Do not mix a Unicode local part with an ASCII domain part.
	 *       Prefer to keep the entire address in one form.
	 *
	 * @see self::get_unicode_domain()
	 *
	 * @return string The domain for machines, potentially containing a punycode
	 *                transcription of Unicode characters.
	 */
	public function get_ascii_domain(): string {
		return $this->encoded_domain;
	}

	/**
	 * Returns the Unicode form of the domain, which is what belongs anywhere a
	 * human meets it. May contain Unicode characters.
	 *
	 * @see self::get_ascii_domain()
	 *
	 * @return string The domain part of the email address.
	 */
	public function get_unicode_domain(): string {
		return $this->decoded_domain;
	}

	/**
	 * Returns the complete email address for the software that accepts no other
	 * form; may contain punycode transliterated Unicode characters.
	 *
	 * Use this method where the address is handed to something ASCII-only, such
	 * as an SMTP envelope without SMTPUTF8. Links can use UTF8, and
	 * are read by humans, so UTF8 is adviable for that purpose.
	 *
	 * @see self::get_unicode_address()
	 *
	 * @return string The complete email address.
	 */
	public function get_ascii_address(): string {
		return $this->localpart . '@' . $this->encoded_domain;
	}

	/**
	 * Returns the complete email address for contexts in which humans
	 * will read it; may contain Unicode characters in the domain.
	 *
	 * Use this method in HTML text nodes that show the address, and in the
	 * `mailto:` link that goes with them, percent-encoded as RFC6068 requires.
	 * A link is read by a human at both ends: the text and the target.
	 *
	 * @see self::get_ascii_address()
	 *
	 * @return string The complete email address.
	 */
	public function get_unicode_address(): string {
		return $this->localpart . '@' . $this->decoded_domain;
	}
}

/**
 * Checks whether a string is well-formed UTF-8.
 *
 * Older WordPress releases have no wp_is_valid_utf8(), so fall back to a
 * pattern match: preg_match() with the /u modifier fails on invalid UTF-8.
 *
 * @param string $value The string to check.
 * @return bool Whether the string is valid UTF-8.
 */
function unicode_email_is_valid_utf8( string $value ): bool {
	if ( function_exists( 'wp_is_valid_utf8' ) ) {
		return wp_is_valid_utf8( $value );
	}

	return 1 === preg_match( '//u', $value );
}

/**
 * Validates an email address, replacing the core is_email() logic.
 *
 * Core calls this filter at every exit from is_email(), and returns whatever the
 * filter returns, so this callback can ignore both the verdict core reached and the
 * context it reached it in, and answer from scratch.
 *
 * @param string|false $value   The value core arrived at. Ignored.
 * @param string       $email   The email address being checked.
 * @param string|null  $context Context under which the email was tested. Ignored.
 * @return string|false The email address if valid, false otherwise.
 */
function unicode_email_is_email( $value, $email, $context = null ) {
	$address = Unicode_Email_Address::from_string( (string) $email );

	return $address ? $address->get_unicode_address() : false;
}
add_filter( 'is_email', 'unicode_email_is_email', 10, 3 );

/**
 * Sanitizes an email address, replacing the core sanitize_email() logic.
 *
 * Strips stray whitespace from the input, then strips trailing dots from the domain.
 * This is designed to recover from cut/paste mistakes without any risk of transforming
 * the input into a different address than the user intended. In particular it does not
 * drop a subdomain or a stretch of local part just because those characters were
 * unexpected: an address that cannot be repaired is rejected instead of rewritten.
 *
 * As with is_email(), core calls this filter at every exit from sanitize_email() and
 * passes the unmodified input as $email, so the mangling core did on its way here is
 * discarded.
 *
 * @param string      $value   The value core arrived at. Ignored.
 * @param string      $email   The email address as provided to sanitize_email().
 * @param string|null $context Context under which the email was sanitized. Ignored.
 * @return string The canonical email address if valid, an empty string otherwise.
 */
function unicode_email_sanitize_email( $value, $email, $context = null ) {
	$email = trim( (string) $email );

	// Extract the address from "Display Name <username@domain>" format.
	if ( 1 === preg_match( '/<([^>]+)>$/', $email, $matches ) ) {
		$email = $matches[1];
	}

	/*
	 * Strip soft hyphens and whitespace adjacent to structural separators (dots and @),
	 * e.g. copy-paste artifacts like "info@example\u{00AD}.com" or "info@example .com".
	 *
	 * In some cases, e.g. autocorrect, some older software has been seen to add the
	 * space for unrecognized TLDs. This re-joins the parts for proper examination.
	 */
	$email = preg_replace( '/[\x{00AD}\s]*([.@])[\x{00AD}\s]*/u', '$1', $email ) ?? $email;

	// Strip a trailing dot from the domain (e.g. if pasted from the end of a sentence).
	if ( str_contains( $email, '@' ) ) {
		list( $local, $domain ) = explode( '@', $email, 2 );
		$domain                 = rtrim( $domain, '.' );
		$email                  = $local . '@' . $domain;
	}

	$address = Unicode_Email_Address::from_string( $email );

	return $address ? $address->get_unicode_address() : '';
}
add_filter( 'sanitize_email', 'unicode_email_sanitize_email', 10, 3 );
