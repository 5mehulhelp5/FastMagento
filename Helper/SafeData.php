<?php
namespace ParkkTech\FastMagento\Helper;

/**
 * Utility for encoding any bizarre or invalid text into a safely stored
 * base64-encoded string, ensuring no parse errors in JSON or OpenSearch.
 */
class SafeData
{
    /**
     * Convert the raw string to valid UTF-8, strip control chars,
     * then base64-encode it.
     *
     * @param string|null $raw
     * @return string Base64-encoded safe string
     */
    public function encodeForOpenSearch(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        // 1) Convert to UTF-8, ignoring invalid sequences.
        //    "TRANSLIT" or "IGNORE" approach can remove or approximate them.
        $utf8 = @\iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        if ($utf8 === false) {
            // fallback if iconv fails entirely, just cast to empty
            $utf8 = '';
        }

        // 2) Remove non-printable / control chars except maybe \n or \r
        //    This regex removes all Unicode "C" (control) categories.
        //    If you do want to keep \t, \n, \r, adapt accordingly.
        $utf8 = \preg_replace('/[\p{C}]+/u', '', $utf8);

        // 3) Base64 encode
        $encoded = \base64_encode($utf8);

        return $encoded ?: '';
    }

    /**
     * Decode from base64 back to original text (if you want to display on PDP).
     *
     * @param string|null $encoded
     * @return string
     */
    public function decodeFromOpenSearch(?string $encoded): string
    {
        if ($encoded === null) {
            return '';
        }
        $decoded = @\base64_decode($encoded, true);
        if ($decoded === false) {
            return '';
        }
        return $decoded;
    }
}
