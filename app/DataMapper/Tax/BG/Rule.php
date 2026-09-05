<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\DataMapper\Tax\BG;

use App\DataMapper\Tax\DE\Rule as DERule;

class Rule extends DERule
{
    /** @var string $seller_region */
    public string $seller_region = 'EU';

    /** @var bool $consumer_tax_exempt */
    public bool $consumer_tax_exempt = false;

    /** @var bool $business_tax_exempt */
    public bool $business_tax_exempt = false;

    /** @var bool $eu_business_tax_exempt */
    public bool $eu_business_tax_exempt = true;

    /** @var bool $foreign_business_tax_exempt */
    public bool $foreign_business_tax_exempt = false;

    /** @var bool $foreign_consumer_tax_exempt */
    public bool $foreign_consumer_tax_exempt = false;

    /** @var float $tax_rate */
    public float $tax_rate = 0;

    /** @var float $reduced_tax_rate */
    public float $reduced_tax_rate = 0;

    // payware: upstream ships 'НДС', which is Russian. Bulgarian is 'ДДС'. The name set here
    // is copied into $tax_name by init() and reaches the line table and the totals block of
    // every invoice this rule rates, because calculateRates() only overrides it in the
    // over-threshold B2C branch - which a seller under the 10 000 EUR OSS threshold never
    // enters. Verified on rendered documents 2026-09-04.
    public string $tax_name1 = 'ДДС';

    /**
     * payware: the tax is named in the language the invoice is written in.
     *
     * <p>The name set on this class is stored onto every line at calculation time and printed
     * in the line table and the totals block, so a static `ДДС` reached a Spanish invoice that
     * carried no other Cyrillic. The tax is Bulgarian either way - a consumer in Spain below
     * the OSS threshold pays Bulgarian VAT at 20%, not Spanish IVA at 21% - so this changes
     * only what it is called, in the reader's language.
     */
    public function init(): self
    {
        $lang = optional($this->client)->locale();

        if (is_string($lang) && str_starts_with($lang, 'es')) {
            $this->tax_name1 = 'IVA';
        } elseif (is_string($lang) && ! str_starts_with($lang, 'bg')) {
            $this->tax_name1 = 'VAT';
        }

        return parent::init();
    }

}
