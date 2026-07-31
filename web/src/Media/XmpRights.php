<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The one metadata block Cycling Commons writes into a stored photo
 * (docs/specs/photo-uploads.md §1.3c). Everything the camera put there has
 * already been destroyed by the time this is applied; these are the only fields
 * any stored file carries, and they were all chosen here.
 *
 * Its job is to make the licence travel with the work. A share-alike licence
 * that vanishes the moment a file is downloaded is not doing its job, so the
 * packet states the terms literally — readable by any tool, forever, whether
 * or not this site still exists.
 *
 * WHAT IS DELIBERATELY ABSENT: dc:creator and cc:attributionName. No display
 * name is ever embedded. Three independent reasons, any one of which decides
 * it. A name in a downloaded file cannot be withdrawn, so embedding one would
 * quietly break the promise that deleting an account anonymizes the credit
 * (§6). Display names are unique only at a given moment — they can be changed
 * and re-claimed, so a baked-in name eventually credits a stranger, which is
 * worse than crediting nobody. And a display name is self-chosen and
 * unverified, so it identifies no one to begin with.
 *
 * Attribution is therefore a UUID link to the photo's page (§5d), whose text
 * we resolve at render time. That is what makes it revocable: changing that
 * page reaches every copy of the file that was ever downloaded or mirrored.
 * The limit is honest and worth stating — an owner is recollectable from a
 * photo for exactly as long as this site is online.
 *
 * @api Called by MediaController before processing an upload.
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
