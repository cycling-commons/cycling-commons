<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Authored XMP packet: licence + photo-page URL, never a display name.
 *
 * @see docs/specs/photo-uploads.md §1
 *
 * @api
 */
final class XmpRights
{
    public const string LICENSE_URL = 'https://creativecommons.org/licenses/by-sa/4.0/';

    public function __construct(
        #[Autowire('%env(APP_SITE_URL)%')]
        private readonly string $siteUrl,
    ) {
    }

    public function photoPageUrl(Uuid $mediaId): string
    {
        return rtrim($this->siteUrl, '/').'/photo/'.$mediaId->toRfc4122();
    }

    public function forPhoto(Uuid $mediaId): string
    {
        $page = self::xml($this->photoPageUrl($mediaId));
        $license = self::xml(self::LICENSE_URL);

        return <<<XMP
            <?xpacket begin="\u{FEFF}" id="W5M0MpCehiHzreSzNTczkc9d"?>
            <x:xmpmeta xmlns:x="adobe:ns:meta/">
             <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
              <rdf:Description rdf:about=""
                xmlns:dc="http://purl.org/dc/elements/1.1/"
                xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/"
                xmlns:cc="http://creativecommons.org/ns#">
               <dc:rights><rdf:Alt><rdf:li xml:lang="x-default">© the photographer. Licensed CC BY-SA 4.0.</rdf:li></rdf:Alt></dc:rights>
               <xmpRights:Marked>True</xmpRights:Marked>
               <xmpRights:WebStatement>{$page}</xmpRights:WebStatement>
               <xmpRights:UsageTerms><rdf:Alt><rdf:li xml:lang="x-default">Licensed under CC BY-SA 4.0. Attribution: see {$page}</rdf:li></rdf:Alt></xmpRights:UsageTerms>
               <cc:license rdf:resource="{$license}"/>
               <cc:attributionURL>{$page}</cc:attributionURL>
              </rdf:Description>
             </rdf:RDF>
            </x:xmpmeta>
            <?xpacket end="w"?>
            XMP;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
