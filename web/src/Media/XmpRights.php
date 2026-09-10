<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

    /**
     * The packet for a Commons file we host a re-encoded copy of.
     *
     * Deliberately NOT {@see forPhoto()}. That one asserts our own licence and
     * points at our own photo page, which is exactly right for a rider's photo
     * and exactly wrong for a stranger's: it would tell anyone reading the
     * file's metadata the wrong author, the wrong licence and the wrong place
     * to look. Here the author, the licence and the attribution target are all
     * theirs, and the only thing we add is an honest note that the bytes were
     * resized and re-encoded.
     *
     * Writing something is not optional. The re-encode drops whatever XMP the
     * file arrived with, so the alternative is publishing an orphan with no
     * author and no licence in it at all, while CC BY-SA asks for the
     * attribution to travel with the work. A caption on one HTML page is not
     * the work travelling: the file gets downloaded, and then it is alone.
     *
     * @param string      $file       the Commons filename, e.g. `Mur de Huy 001.jpg`
     * @param string      $credit     the uploader as Commons states them
     * @param string|null $creditUser their Commons username, when there is one
     * @param string      $licence    a name {@see LicenceUrls} knows
     */
    public function forCommonsFile(string $file, string $credit, ?string $creditUser, string $licence): string
    {
        $page = 'https://commons.wikimedia.org/wiki/File:'.rawurlencode(str_replace(' ', '_', $file));
        $deed = LicenceUrls::urlFor($licence);
        if (null === $deed) {
            // Unreachable through the fetch path, which refuses the file first
            // (CommonsApi::fileInfo). Guarded anyway: a packet asserting a
            // licence it cannot identify is worse than no packet.
            throw new \InvalidArgumentException(sprintf('No deed known for licence "%s".', $licence));
        }

        $author = self::xml($credit);
        $rights = self::xml(sprintf('© %s. Licensed %s.', $credit, $licence));
        $terms = self::xml(sprintf(
            'Licensed %s. Attribution: %s. This copy was resized and re-encoded to WebP; the original is on Wikimedia Commons.',
            $licence,
            $credit,
        ));
        $pageXml = self::xml($page);
        $deedXml = self::xml($deed);
        $creator = null === $creditUser || '' === $creditUser
            ? ''
            : "\n   <xmpRights:Owner><rdf:Bag><rdf:li>".self::xml('https://commons.wikimedia.org/wiki/User:'.rawurlencode(str_replace(' ', '_', $creditUser))).'</rdf:li></rdf:Bag></xmpRights:Owner>';

        return <<<XMP
            <?xpacket begin="\u{FEFF}" id="W5M0MpCehiHzreSzNTczkc9d"?>
            <x:xmpmeta xmlns:x="adobe:ns:meta/">
             <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
              <rdf:Description rdf:about=""
                xmlns:dc="http://purl.org/dc/elements/1.1/"
                xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/"
                xmlns:cc="http://creativecommons.org/ns#">
               <dc:creator><rdf:Seq><rdf:li>{$author}</rdf:li></rdf:Seq></dc:creator>
               <dc:source>{$pageXml}</dc:source>
               <dc:rights><rdf:Alt><rdf:li xml:lang="x-default">{$rights}</rdf:li></rdf:Alt></dc:rights>
               <xmpRights:Marked>True</xmpRights:Marked>
               <xmpRights:WebStatement>{$pageXml}</xmpRights:WebStatement>
               <xmpRights:UsageTerms><rdf:Alt><rdf:li xml:lang="x-default">{$terms}</rdf:li></rdf:Alt></xmpRights:UsageTerms>{$creator}
               <cc:license rdf:resource="{$deedXml}"/>
               <cc:attributionName>{$author}</cc:attributionName>
               <cc:attributionURL>{$pageXml}</cc:attributionURL>
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
