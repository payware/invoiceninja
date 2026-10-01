<?php

/**
 * payware: what a distributed document's file is called.
 *
 *   <Type>_<Number>_<YYYY-MM-DD>_<Issuer>_<Receiver>
 *   Invoice_0000000123_2026-10-01_payware_Market-Point-Management
 *
 * Invoice Ninja's own convention is <Type>_<Number>; this extends it, and ERPNext names
 * Zephyr's documents the same way (erpnext: payware_bg/utils/filenames.py), so a
 * recipient's folder sorts the two together. VG's decisions, 2026-10-01: the type word is
 * English always - upstream translates it per client, so a Bulgarian client got
 * "Faktura_" - and parties go by short forms. ASCII only: a Cyrillic attachment name is an
 * RFC 2231 parameter not every mail client decodes, so Cyrillic is transliterated by the
 * Bulgarian official system (Закон за транслитерацията), the same table ERPNext uses.
 *
 * Reached through BaseModel::numberFormatter(), which every PDF name in the app goes
 * through - email attachments, downloads, the client portal, zips, the PDF's own title -
 * and also the path a generated PDF is stored under. Writing and deleting both call it,
 * so storage stays consistent; a PDF stored under the old name is simply regenerated.
 * Anything that is not one of the four types below keeps upstream's name.
 */

namespace App\Utils;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use Illuminate\Support\Str;

class PaywareFileName
{
    /** Our own companies, by VAT number, as they are written in file names. */
    public const SHORT_BY_VAT = [
        'BG205863621' => 'payware',
        'BG205209493' => 'Zephyr-Inv',
    ];

    public const MAX_PARTY = 30;

    private const TYPES = [
        Invoice::class => 'Invoice',
        Credit::class => 'Credit-note',
        Quote::class => 'Quote',
        PurchaseOrder::class => 'Purchase-order',
    ];

    // Закон за транслитерацията, чл. 4. "ия" at the end of a word is "ia" (чл. 4, ал. 2).
    private const BG = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
        'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a',
        'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
    ];

    // Dropped as whole words, and only when something else is left.
    private const LEGAL_FORMS = [
        'eood', 'ood', 'ead', 'ad', 'et', 'sd', 'kd', 'kda', 'dzzd', 'zad',
        'llc', 'ltd', 'limited', 'inc', 'corp', 'corporation', 'co', 'plc', 'llp', 'lp', 'pbc',
        'gmbh', 'ag', 'kg', 'mbh', 'sa', 'sas', 'sarl', 'srl', 'sl', 'spa', 'bv', 'nv', 'oy',
        'ab', 'as', 'aps', 'uab', 'unlimited', 'company',
    ];

    /** The file name without extension, or null to keep upstream's. */
    public static function for($entity): ?string
    {
        $type = self::TYPES[get_class($entity)] ?? null;
        $number = trim((string) ($entity->number ?? ''));

        if (! $type || $number === '' || empty($entity->date)) {
            return null;
        }

        $party = $entity instanceof PurchaseOrder ? $entity->vendor : $entity->client;

        $parts = [
            $type,
            preg_replace('/[^A-Za-z0-9-]+/', '-', $number),
            date('Y-m-d', strtotime((string) $entity->date)),
            self::companyShort($entity->company),
            $party ? self::partyShort($party) : '',
        ];

        return implode('_', array_filter($parts, fn ($p) => $p !== ''));
    }

    public static function companyShort($company): string
    {
        if (! $company) {
            return '';
        }

        $vat = strtoupper(trim((string) ($company->settings->vat_number ?? '')));

        return self::SHORT_BY_VAT[$vat] ?? self::slug((string) ($company->settings->name ?? ''));
    }

    /** A client or vendor. One of our own companies answers for itself. */
    public static function partyShort($party): string
    {
        $vat = strtoupper(trim((string) ($party->vat_number ?? '')));

        return self::SHORT_BY_VAT[$vat] ?? self::slug((string) $party->present()->name());
    }

    public static function transliterate(string $text): string
    {
        return preg_replace_callback('/\w+/u', function ($m) {
            $word = $m[0];
            $tail = '';

            if (mb_strlen($word) > 2 && mb_strtolower(mb_substr($word, -2)) === 'ия') {
                $ending = mb_substr($word, -2);
                $tail = mb_strtoupper($ending) === $ending ? 'IA' : 'ia';
                $word = mb_substr($word, 0, -2);
            }

            $out = '';
            foreach (mb_str_split($word) as $ch) {
                $lower = mb_strtolower($ch);
                if (isset(self::BG[$lower])) {
                    $out .= $ch !== $lower ? ucfirst(self::BG[$lower]) : self::BG[$lower];
                } else {
                    $out .= $ch;
                }
            }

            return $out . $tail;
        }, $text);
    }

    /** A name as a file-name field: ASCII, hyphen-joined, legal form dropped, capped. */
    public static function slug(string $text, int $limit = self::MAX_PARTY): string
    {
        $text = self::transliterate($text);
        // Dotted legal forms split into letters, so they go before the split: Polish
        // sp. z o.o., Czech and Slovak (spol.) s r.o. and a.s.
        $text = preg_replace('/\bsp\.?\s*z\s*o\.?\s*o\.?/iu', ' ', $text);
        $text = preg_replace('/\b(?:spol\.\s*)?s\.?\s*r\.\s*o\.?/iu', ' ', $text);
        $text = preg_replace('/\ba\.\s*s\.(?=\s|$)/iu', ' ', $text);
        $text = Str::ascii($text);

        $words = preg_split('/[^A-Za-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $kept = array_values(array_filter($words, fn ($w) => ! in_array(strtolower($w), self::LEGAL_FORMS, true)));
        $words = $kept ?: $words;

        $out = '';
        foreach ($words as $w) {
            $next = $out === '' ? $w : "{$out}-{$w}";
            if (strlen($next) > $limit) {
                break;
            }
            $out = $next;
        }

        return $out !== '' ? $out : ($words ? substr($words[0], 0, $limit) : '');
    }
}
