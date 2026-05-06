<?php
/**
 * Beacon v3.1.0
 *
 * Grav plugin for managing SEO meta tags, Open Graph, Twitter Cards,
 * and Schema.org JSON-LD structured data.
 *
 * Originally based on grav-plugin-seo by Paul Massendari.
 * Licensed under the MIT license, see LICENSE.
 *
 * @package     Beacon
 * @version     3.1.0
 * @link        <https://github.com/ccmmgg/grav-plugin-seo>
 * @author      Charlie
 * @copyright   2026, Charlie
 * @license     <http://opensource.org/licenses/MIT>        MIT
 */

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Data\Blueprints;
use RocketTheme\Toolbox\Event\Event;


/**
 * SEO Plugin
 *
 * This plugin adds an user-friendly SEO tab for your user to manage metadata tags
 * and appearance on Search Engine Results and Social Networks.
 */

class SeoPlugin extends Plugin
{
    private string $jsonLdOutput = '';
    private string $canonicalUrl = '';

    /** -------------
     * Public methods
     * --------------
     */

    /**
     * Return a list of subscribed events.
     *
     * @return array    The list of events of the plugin of the form
     *                      'name' => ['method_name', priority].
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageInitialized'    => ['onPageInitialized', 0],
        ];
    }

    private function cleanArray(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = $this->cleanArray($value);
            }
            if (empty($value) && $value !== 0 && $value !== '0') {
                unset($array[$key]);
            }
        }
        return $array;
    }

    private function resolvePublicUrl(string $value): string
    {
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        return rtrim($this->grav['uri']->base(), '/') . '/' . ltrim($value, '/');
    }

    private function seoGetImage(?string $imageUrl): array
    {
        if (empty($imageUrl)) {
            return ['width' => '0', 'height' => '0', 'url' => ''];
        }

        try {
            if (!preg_match('~((\/[^\/]+)+)\/([^\/]+)~', $imageUrl, $matches)) {
                throw new \RuntimeException('Invalid image URL format');
            }

            $imagePath = $matches[1];
            $imageName = $matches[3];

            $page = $this->grav['page']->find($imagePath);
            if (!$page) {
                throw new \RuntimeException("Page not found: $imagePath");
            }

            $images = $page->media()->images();
            if (empty($images)) {
                throw new \RuntimeException('No images found on page');
            }

            $availableImages = array_keys($images);
            $imageIndex      = array_search($imageName, $availableImages);
            if ($imageIndex === false) {
                throw new \RuntimeException('Specific image not found');
            }

            $image = $images[$availableImages[$imageIndex]];

            if (!$image || !$image->path() || !file_exists($image->path())) {
                throw new \RuntimeException('Image file invalid or inaccessible');
            }

            $dimensions = @getimagesize($image->path());
            if ($dimensions === false) {
                throw new \RuntimeException('Could not read image dimensions');
            }

            return ['width' => (string)$dimensions[0], 'height' => (string)$dimensions[1], 'url' => $image->url()];

        } catch (\Exception $e) {
            $this->grav['log']->debug('SEO Plugin - Image Warning: ' . $e->getMessage());
            return ['width' => '0', 'height' => '0', 'url' => ''];
        }
    }

    private const MARKDOWN_RULES = [
        '/{%[\s\S]*?%}[\s\S]*?/'           => '',   // Twig includes
        '/<style[^>]*?>.*?<\/style>/si'     => '',   // style blocks
        '/<script[^>]*?>.*?<\/script>/si'   => '',   // script blocks
        '/^#+\s*(.*)$/m'                    => '$1', // headings
        '/^[*\-_]{3,}$/m'                  => '',   // horizontal rules
        '/!\[([^\]]*)\]\([^)]+\)/'         => '',   // images
        '/\[([^\]]+)\]\([^)]+\)/'          => '$1', // links
        '/[*_]{2}(.*?)[*_]{2}/'            => '$1', // bold
        '/[*_](.*?)[*_]/'                  => '$1', // italic
        '/~~(.*?)~~/'                      => '$1', // strikethrough
        '/:`(.*?)`/'                       => '$1', // inline code
        '/^```[\s\S]*?```$/m'              => '',   // code blocks
        '/^[*\-+]\s+(.*)$/m'              => '$1', // unordered lists
        '/^\d+\.\s+(.*)$/m'               => '$1', // ordered lists
        '/^>\s*(.*)$/m'                   => '$1', // blockquotes
        '/<!--[\s\S]*?-->/'               => '',   // HTML comments
    ];

    private function cleanMarkdown(string $text, int $maxLength = 320): string
    {
        $text = strip_tags($text);

        foreach (self::MARKDOWN_RULES as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = preg_replace('/\. \./', '.', $text);
        $text = trim($text);

        return mb_substr($text, 0, $maxLength);
    }

    private function extractSummary(string $rawContent, int $maxLength = 320): string
    {
        // Strip HTML and Twig, normalise line endings
        $text = strip_tags($rawContent);
        $text = preg_replace('/{%[\s\S]*?%}/', '', $text);
        $text = preg_replace('/<!--[\s\S]*?-->/', '', $text);
        $text = str_replace("\r\n", "\n", $text);

        $paragraphs = preg_split('/\n{2,}/', trim($text));

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') continue;

            // Skip headings (lines starting with #)
            if (preg_match('/^#+\s/', $para)) continue;

            // Strip inline markdown from the paragraph
            $clean = $para;
            foreach (self::MARKDOWN_RULES as $pattern => $replacement) {
                $clean = preg_replace($pattern, $replacement, $clean);
            }
            $clean = preg_replace('/\s+/', ' ', trim($clean));

            // Skip if the paragraph is purely list items (no prose sentences)
            $lines = explode("\n", $para);
            $allList = count(array_filter($lines, fn($l) => preg_match('/^[*\-+\d]/', trim($l)))) === count($lines);
            if ($allList) continue;

            if ($clean !== '') {
                return mb_substr($clean, 0, $maxLength);
            }
        }

        // Fallback: flatten everything
        return $this->cleanMarkdown($rawContent, $maxLength);
    }
    

    /**
     * Initialize configuration
     */
    public function onPluginsInitialized()
    {

        // Set default events
        $events = [
            'onTwigTemplatePaths'  => ['onTwigTemplatePaths', 0],
            'onOutputGenerated'    => ['onOutputGenerated', 0],
        ];

        if ($this->isAdmin()) {
            $this->active = false;
            $events = [
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onBlueprintCreated'  => ['onBlueprintCreated', 0],
            ];
        }

        $this->enable($events);
    }

    public function onPageInitialized()
    {
        $page = $this->grav['page'];
        $config = $this->mergeConfig($page);
        $content = strip_tags($page->content());
        $cleanedMarkdown = $this->extractSummary($page->content())
            ?: ($this->config['plugins']['seo']['default_description'] ?? '');
        $microdata   = [];
        $outputjson  = '';
        $meta        = $page->metadata(null);

        $meta = $this->applyGoogleMeta($page, $meta, $cleanedMarkdown);
        $meta = $this->applyTwitterMeta($page, $meta, $cleanedMarkdown, $config);
        $meta = $this->applyOpenGraphMeta($page, $meta, $cleanedMarkdown, $config);
        $page->metadata($meta);

        array_push($microdata, ...$this->buildBreadcrumbMicrodata($page));
        array_push($microdata, ...$this->buildMusicEventMicrodata($page));
        array_push($microdata, ...$this->buildEventMicrodata($page));
        array_push($microdata, ...$this->buildPersonMicrodata($page));
        array_push($microdata, ...$this->buildOrganizationMicrodata($page));
        array_push($microdata, ...$this->buildRestaurantMicrodata($page));
        array_push($microdata, ...$this->buildProductMicrodata($page));

        $articleMicrodata = $this->buildArticleMicrodata($page, $content);
        if ($articleMicrodata) {
            $microdata['article'] = $articleMicrodata;
        }

        $microdata = $this->cleanArray($microdata);

        foreach ($microdata as $item) {
            $outputjson .= PHP_EOL . '<script type="application/ld+json">' . PHP_EOL
                . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                . PHP_EOL . '</script>';
        }

        $customjson = $page->header()->add_json ?? null;
        if (!empty($customjson)) {
            foreach ($customjson as $json) {
                $outputjson .= PHP_EOL . '<script type="application/ld+json">' . PHP_EOL
                    . $json['custom_json']
                    . PHP_EOL . '</script>';
            }
        }

        $this->grav['twig']->twig_vars['json'] = $outputjson;
        $this->grav['twig']->twig_vars['myvar'] = $outputjson;
        $this->jsonLdOutput = $outputjson;
        $this->canonicalUrl = $page->canonical(true);
    }

    private function buildBreadcrumbMicrodata(Page $page): array
    {
        $ancestors = [];
        $current = $page->parent();
        while ($current && !$current->root()) {
            array_unshift($ancestors, $current);
            $current = $current->parent();
        }

        if (empty($ancestors)) {
            return [];
        }

        $items    = [];
        $position = 1;
        foreach ($ancestors as $ancestor) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $ancestor->title(),
                'item'     => $ancestor->canonical(true),
            ];
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => $page->title(),
            'item'     => $page->canonical(true),
        ];

        return [[
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ]];
    }

    private function buildMusicEventMicrodata(Page $page): array
    {
        $result = [];
        if (!property_exists($page->header(), 'musiceventenabled')) return $result;
        if (!$page->header()->musiceventenabled || !$this->config['plugins']['seo']['musicevent']) return $result;

        $musiceventsarray = $page->header()->musicevents ?? [];
        if (!is_array($musiceventsarray) || empty($musiceventsarray)) return $result;

        foreach ($musiceventsarray as $event) {
            $performerarray  = [];
            $workarray       = [];
            $musiceventimage = null;

            if (!empty($event['musicevent_performer']) && is_array($event['musicevent_performer'])) {
                foreach ($event['musicevent_performer'] as $artist) {
                    $performerarray[] = [
                        '@type' => $artist['performer_type'] ?? 'PerformingGroup',
                        'name'  => $artist['name'] ?? '',
                        'sameAs' => $artist['sameAs'] ?? '',
                    ];
                }
            }
            if (!empty($event['musicevent_workPerformed']) && is_array($event['musicevent_workPerformed'])) {
                foreach ($event['musicevent_workPerformed'] as $work) {
                    $workarray[] = ['name' => $work['name'] ?? '', 'sameAs' => $work['sameAs'] ?? ''];
                }
            }
            if (!empty($event['musicevent_image'])) {
                $imagedata = $this->seoGetImage($event['musicevent_image']);
                if (!empty($imagedata['url'])) {
                    $musiceventimage = [
                        '@type'  => 'ImageObject',
                        'width'  => $imagedata['width'],
                        'height' => $imagedata['height'],
                        'url'    => $this->grav['uri']->base() . $imagedata['url'],
                    ];
                }
            }

            $eventData = [
                '@context'    => 'https://schema.org',
                '@type'       => 'MusicEvent',
                'name'        => $event['musicevent_location_name'] ?? '',
                'location'    => [
                    '@type'   => 'MusicVenue',
                    'name'    => $event['musicevent_location_name'] ?? '',
                    'address' => $event['musicevent_location_address'] ?? '',
                ],
                'description' => $event['musicevent_description'] ?? '',
                'url'         => $event['musicevent_url'] ?? '',
                'offers'      => [
                    '@type'        => 'Offer',
                    'price'        => $event['musicevent_offers_price'] ?? '',
                    'priceCurrency' => $event['musicevent_offers_priceCurrency'] ?? '',
                    'url'          => $event['musicevent_offers_url'] ?? '',
                ],
            ];

            if (!empty($performerarray))  $eventData['performer']    = $performerarray;
            if (!empty($workarray))       $eventData['workPerformed'] = $workarray;
            if ($musiceventimage)         $eventData['image']        = $musiceventimage;
            if (!empty($event['musicevent_startdate'])) {
                $eventData['startDate'] = date("c", strtotime($event['musicevent_startdate']));
            }
            if (!empty($event['musicevent_enddate'])) {
                $eventData['endDate'] = date("c", strtotime($event['musicevent_enddate']));
            }

            $result[] = $eventData;
        }

        return $result;
    }

    private function buildEventMicrodata(Page $page): array
    {
        $result = [];
        if (!property_exists($page->header(), 'eventenabled')) return $result;
        if (!$page->header()->eventenabled || !$this->config['plugins']['seo']['event']) return $result;

        $eventsarray = $page->header()->addevent ?? [];
        if (!is_array($eventsarray) || empty($eventsarray)) return $result;

        foreach ($eventsarray as $event) {
            $address = ['@type' => 'PostalAddress'];
            if (!empty($event['event_location_address_addressLocality'])) {
                $address['addressLocality'] = $event['event_location_address_addressLocality'];
            }
            if (!empty($event['event_location_address_addressRegion'])) {
                $address['addressRegion'] = $event['event_location_address_addressRegion'];
            }
            if (!empty($event['event_location_streetAddress'])) {
                $address['streetAddress'] = $event['event_location_streetAddress'];
            }

            $offers = ['@type' => 'Offer'];
            if (!empty($event['event_offers_price']))    $offers['price']         = $event['event_offers_price'];
            if (!empty($event['event_offers_currency'])) $offers['priceCurrency'] = $event['event_offers_currency'];
            if (!empty($event['event_offers_url']))      $offers['url']           = $event['event_offers_url'];

            $eventData = [
                '@context' => 'https://schema.org',
                '@type'    => 'Event',
                'name'     => $event['event_name'] ?? '',
                'location' => ['@type' => 'Place', 'name' => $event['event_location_name'] ?? '', 'address' => $address],
            ];

            if (!empty($event['musicevent_location_url'])) {
                $eventData['location']['url'] = $event['musicevent_location_url'];
            }
            if (!empty($event['event_description'])) {
                $eventData['description'] = $event['event_description'];
            }
            if (count(array_filter($offers)) > 1) {
                $eventData['offers'] = $offers;
            }
            if (!empty($event['event_startDate'])) {
                $ts = strtotime($event['event_startDate']);
                if ($ts) $eventData['startDate'] = date("c", $ts);
            }
            if (!empty($event['event_endDate'])) {
                $ts = strtotime($event['event_endDate']);
                if ($ts) $eventData['endDate'] = date("c", $ts);
            }

            $result[] = array_filter($eventData, fn($v) => $v !== null && $v !== '');
        }

        return $result;
    }

    private function buildPersonMicrodata(Page $page): array
    {
        $result = [];
        if (!property_exists($page->header(), 'personenabled')) return $result;
        if (!$page->header()->personenabled || !$this->config['plugins']['seo']['person']) return $result;

        $personarray = $page->header()->addperson ?? [];
        if (!is_array($personarray) || empty($personarray)) return $result;

        foreach ($personarray as $person) {
            $result[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'Person',
                'name'     => $person['person_name'] ?? null,
                'address'  => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $person['person_address_addressLocality'] ?? null,
                    'addressRegion'   => $person['person_address_addressRegion'] ?? null,
                ],
                'jobTitle' => $person['person_jobTitle'] ?? null,
            ];
        }

        return $result;
    }

    private function buildOrganizationMicrodata(Page $page): array
    {
        $result = [];
        $cfg    = $this->config['plugins']['seo'];

        $globalEnabled = !empty($cfg['organization_on_all_pages']);
        $pageEnabled   = property_exists($page->header(), 'orgaenabled') && $page->header()->orgaenabled;

        if (!$globalEnabled && !$pageEnabled) return $result;
        if (!$cfg['organization']) return $result;

        // Merge: global defaults, then page-level overrides on top
        $globalOrga = $cfg['organization_defaults'] ?? [];
        $orga       = array_merge($globalOrga, $page->header()->orga ?? []);
        $founderarray    = [];
        $similararray    = [];
        $areaservedarray = [];
        $openingHours    = [];
        $offerarray      = [];
        $orgarating      = null;

        foreach ($orga['founders'] ?? [] as $founder) {
            $founderarray[] = ['@type' => 'Person', 'name' => $founder['name'] ?? null];
        }
        foreach ($orga['similar'] ?? [] as $similar) {
            $similararray[] = $similar['sameas'];
        }
        foreach ($orga['areaserved'] ?? [] as $areaserved) {
            $areaservedarray[] = $areaserved['area'];
        }
        foreach ($orga['openingHours'] ?? [] as $hours) {
            $openingHours[] = $hours['entry'];
        }
        foreach ($orga['offercatalog'] ?? [] as $offer) {
            if (array_key_exists('offereditem', $offer)) {
                foreach ($offer['offereditem'] as $service) {
                    $offerarray[] = [
                        '@type'           => 'OfferCatalog',
                        'name'            => $offer['offer'] ?? null,
                        'description'     => $offer['description'] ?? null,
                        'url'             => $offer['url'] ?? null,
                        'image'           => $offer['image'] ?? null,
                        'itemListElement' => [
                            '@type'       => 'Offer',
                            'itemOffered' => ['@type' => 'Service', 'name' => $service['name'] ?? null, 'url' => $service['url'] ?? null],
                        ],
                    ];
                }
            } else {
                $offerarray[] = [
                    '@type'       => 'OfferCatalog',
                    'name'        => $offer['offer'] ?? null,
                    'description' => $offer['description'] ?? null,
                    'url'         => $offer['url'] ?? null,
                    'image'       => $offer['image'] ?? null,
                ];
            }
        }

        if (property_exists($page->header(), 'orgaratingenabled') && $page->header()->orgaratingenabled) {
            $orgarating = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $orga['ratingValue'] ?? null,
                'reviewCount' => $orga['reviewCount'] ?? null,
            ];
        }

        $result[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'Organization',
            'name'            => $orga['name'] ?? null,
            'legalname'       => $orga['legalname'] ?? null,
            'taxid'           => $orga['taxid'] ?? null,
            'vatid'           => $orga['vatid'] ?? null,
            'areaServed'      => $areaservedarray ?: null,
            'description'     => $orga['description'] ?? null,
            'address'         => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $orga['streetaddress'] ?? null,
                'addressLocality' => $orga['city'] ?? null,
                'addressRegion'   => $orga['state'] ?? null,
                'postalCode'      => $orga['zipcode'] ?? null,
            ],
            'telephone'       => $orga['phone'] ?? null,
            'logo'            => $orga['logo'] ?? null,
            'url'             => $orga['url'] ?? null,
            'openingHours'    => $openingHours ?: null,
            'email'           => $orga['email'] ?? null,
            'foundingDate'    => $orga['foundingDate'] ?? null,
            'aggregateRating' => $orgarating,
            'paymentAccepted' => $orga['paymentAccepted'] ?? null,
            'founders'        => $founderarray ?: null,
            'sameAs'          => $similararray ?: null,
            'hasOfferCatalog' => $offerarray ?: null,
        ];

        return $result;
    }

    private function buildRestaurantMicrodata(Page $page): array
    {
        $result = [];
        if (!property_exists($page->header(), 'restaurantenabled')) return $result;
        if (!$page->header()->restaurantenabled || !$this->config['plugins']['seo']['restaurant']) return $result;

        $restaurant      = $page->header()->restaurant ?? [];
        $restaurantimage = null;

        if (isset($restaurant['image'])) {
            $imagedata       = $this->seoGetimage($restaurant['image']);
            $restaurantimage = [
                '@type'  => 'ImageObject',
                'width'  => $imagedata['width'],
                'height' => $imagedata['height'],
                'url'    => $this->grav['uri']->base() . $imagedata['url'],
            ];
        }

        $result[] = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Restaurant',
            'name'          => $restaurant['name'] ?? null,
            'address'       => [
                '@type'           => 'PostalAddress',
                'addressLocality' => $restaurant['address_addressLocality'] ?? null,
                'addressRegion'   => $restaurant['address_addressRegion'] ?? null,
                'streetAddress'   => $restaurant['address_streetAddress'] ?? null,
                'postalCode'      => $restaurant['address_postalCode'] ?? null,
            ],
            'servesCuisine' => $restaurant['servesCuisine'] ?? null,
            'priceRange'    => $restaurant['priceRange'] ?? null,
            'image'         => $restaurantimage,
            'telephone'     => $restaurant['telephone'] ?? null,
        ];

        return $result;
    }

    private function buildProductMicrodata(Page $page): array
    {
        $result = [];
        if (!property_exists($page->header(), 'productenabled')) return $result;
        if (!$page->header()->productenabled || !$this->config['plugins']['seo']['product']) return $result;

        $product      = $page->header()->product ?? [];
        $productimage = [];
        $offer        = [];

        foreach ($product['image'] ?? [] as $imagearray) {
            foreach ($imagearray as $imagepath) {
                $imagedata      = $this->seoGetimage($imagepath);
                $productimage[] = $this->grav['uri']->base() . $imagedata['url'];
            }
        }
        foreach ($product['addoffer'] ?? [] as $key => $offerdata) {
            $offer[$key] = [
                '@type'          => 'Offer',
                'priceCurrency'  => $offerdata['offer_priceCurrency'] ?? null,
                'price'          => $offerdata['offer_price'] ?? null,
                'validFrom'      => $offerdata['offer_validFrom'] ?? null,
                'priceValidUntil' => $offerdata['offer_validUntil'] ?? null,
                'availability'   => $offerdata['offer_availability'] ?? null,
            ];
        }

        $result[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'Product',
            'name'            => $product['name'] ?? null,
            'category'        => $product['category'] ?? null,
            'brand'           => ['@type' => 'Thing', 'name' => $product['brand'] ?? null],
            'offers'          => $offer ?: null,
            'description'     => $product['description'] ?? null,
            'image'           => $productimage ?: null,
            'aggregateRating' => [
                '@type'       => 'AggregateRating',
                'ratingValue' => $product['ratingValue'] ?? null,
                'reviewCount' => $product['reviewCount'] ?? null,
            ],
        ];

        return $result;
    }

    private function buildArticleMicrodata(Page $page, string $content): array
    {
        if (!property_exists($page->header(), 'articleenabled')) return [];
        if (!$page->header()->articleenabled || !$this->config['plugins']['seo']['article']) return [];

        $article  = $page->header()->article ?? [];
        $microdata = [
            '@context'          => 'https://schema.org',
            '@type'             => 'Article',
            'headline'          => $article['headline'] ?? $page->title(),
            'mainEntityOfPage'  => ['@type' => 'WebPage', 'url' => $this->grav['uri']->base()],
            'articleBody'       => $this->cleanMarkdown($content),
            'datePublished'     => date("c", strtotime($article['datePublished'] ?? '') ?: $page->date()),
            'dateModified'      => date("c", strtotime($article['dateModified'] ?? '') ?: $page->date()),
            'description'       => $article['description'] ?? substr($content, 0, 140),
        ];

        $author = $article['author']
            ?? ($page->header()->author ?? null)
            ?? ($this->config['plugins']['seo']['default_author'] ?? null)
            ?: $this->config->get('site.author.name');
        if (!empty($author)) {
            $microdata['author'] = $author;
        }

        $publisherName = $article['publisher_name']
            ?? ($this->config['plugins']['seo']['publisher_name'] ?? null);
        $publisherLogo = $article['publisher_logo_url']
            ?? ($this->config['plugins']['seo']['publisher_logo'] ?? null);
        if (!empty($publisherName)) {
            $microdata['publisher'] = ['@type' => 'Organization', 'name' => $publisherName];
        }
        if (!empty($publisherLogo)) {
            $imagedata = $this->seoGetimage($publisherLogo);
            $microdata['publisher']['logo'] = [
                '@type'  => 'ImageObject',
                'url'    => $this->grav['uri']->base() . $imagedata['url'],
                'width'  => $imagedata['width'],
                'height' => $imagedata['height'],
            ];
        }
        if (isset($article['image_url'])) {
            $imagedata = $this->seoGetimage($article['image_url']);
            $microdata['image'] = [
                '@type'  => 'ImageObject',
                'url'    => $this->grav['uri']->base() . $imagedata['url'],
                'width'  => $imagedata['width'],
                'height' => $imagedata['height'],
            ];
        }

        return $microdata;
    }

     
    


    /**
     * Extend page blueprints with SEO configuration options.
     *
     * @param Event $event
     */
    public function onBlueprintCreated(Event $event)
    {
        $newtype = $event['type'];
        if (0 === strpos($newtype, 'modular/')) {
            return;
        }
        $blueprint = $event['blueprint'];
        if ($blueprint->get('form/fields/tabs', null, '/')) {
            $blueprints = new Blueprints(__DIR__ . '/blueprints/');
            $extends    = $blueprints->get($this->name);
            $blueprint->extend($extends, true);
        }
    }


    private function applyGoogleMeta(Page $page, array $meta, string $cleanedMarkdown): array
    {
        if (isset($page->header()->googletitle)) {
            $page->header()->displaytitle = $page->header()->title;
            $page->header()->title = $page->header()->googletitle;
        }
        $meta['description']['name']    = 'description';
        $meta['description']['content'] = $page->header()->googledesc ?? $cleanedMarkdown;
        return $meta;
    }

    private function applyTwitterMeta(Page $page, array $meta, string $cleanedMarkdown, $config): array
    {
        if (!property_exists($page->header(), 'twitterenable') || $page->header()->twitterenable != 'true') {
            return $meta;
        }
        if (isset($config['twitterid'])) {
            $meta['twitter:site'] = ['name' => 'twitter:site', 'property' => 'twitter:site', 'content' => $config->twitterid];
        }
        $meta['twitter:card'] = [
            'name' => 'twitter:card', 'property' => 'twitter:card',
            'content' => $page->header()->twittercardoptions ?? 'summary_large_image',
        ];
        $meta['twitter:title'] = [
            'name' => 'twitter:title', 'property' => 'twitter:title',
            'content' => $page->header()->twittertitle ?? ($page->title() . ' | ' . $this->config->get('site.title')),
        ];
        $meta['twitter:description'] = [
            'name' => 'twitter:description', 'property' => 'twitter:description',
            'content' => $page->header()->twitterdescription ?? $cleanedMarkdown,
        ];
        if (isset($page->header()->twittershareimg)) {
            $imagedata = $this->seoGetimage($page->header()->twittershareimg);
            $meta['twitter:image'] = [
                'name' => 'twitter:image', 'property' => 'twitter:image',
                'content' => $this->grav['uri']->base() . $imagedata['url'],
            ];
        } elseif (!empty($page->media()->images())) {
            $images = $page->media()->images();
            $first  = array_shift($images);
            $meta['twitter:image'] = [
                'name' => 'twitter:image', 'property' => 'twitter:image',
                'content' => $this->grav['uri']->base() . $first->url(),
            ];
        } elseif (!empty($this->config['plugins']['seo']['default_social_image'])) {
            $meta['twitter:image'] = [
                'name' => 'twitter:image', 'property' => 'twitter:image',
                'content' => $this->resolvePublicUrl($this->config['plugins']['seo']['default_social_image']),
            ];
        }
        $meta['twitter:url'] = ['name' => 'twitter:url', 'property' => 'twitter:url', 'content' => $page->url(true)];
        return $meta;
    }

    private static array $LOCALE_MAP = [
        'af' => 'af_ZA', 'ar' => 'ar_AR', 'az' => 'az_AZ', 'be' => 'be_BY',
        'bg' => 'bg_BG', 'bs' => 'bs_BA', 'ca' => 'ca_ES', 'cs' => 'cs_CZ',
        'cy' => 'cy_GB', 'da' => 'da_DK', 'de' => 'de_DE', 'el' => 'el_GR',
        'en' => 'en_US', 'eo' => 'eo_EO', 'es' => 'es_ES', 'et' => 'et_EE',
        'eu' => 'eu_ES', 'fa' => 'fa_IR', 'fi' => 'fi_FI', 'fr' => 'fr_FR',
        'gl' => 'gl_ES', 'he' => 'he_IL', 'hr' => 'hr_HR', 'hu' => 'hu_HU',
        'hy' => 'hy_AM', 'id' => 'id_ID', 'is' => 'is_IS', 'it' => 'it_IT',
        'ja' => 'ja_JP', 'ka' => 'ka_GE', 'kk' => 'kk_KZ', 'ko' => 'ko_KR',
        'lt' => 'lt_LT', 'lv' => 'lv_LV', 'mk' => 'mk_MK', 'ms' => 'ms_MY',
        'mt' => 'mt_MT', 'nl' => 'nl_NL', 'nn' => 'nn_NO', 'no' => 'nb_NO',
        'pl' => 'pl_PL', 'pt' => 'pt_PT', 'ro' => 'ro_RO', 'ru' => 'ru_RU',
        'sk' => 'sk_SK', 'sl' => 'sl_SI', 'sq' => 'sq_AL', 'sr' => 'sr_RS',
        'sv' => 'sv_SE', 'th' => 'th_TH', 'tr' => 'tr_TR', 'uk' => 'uk_UA',
        'uz' => 'uz_UZ', 'vi' => 'vi_VN', 'zh' => 'zh_CN',
    ];

    private function applyOpenGraphMeta(Page $page, array $meta, string $cleanedMarkdown, $config): array
    {
        $facebookEnabled = property_exists($page->header(), 'facebookenable') && $page->header()->facebookenable == 'true';

        // Basic OG tags — always emitted so social previews work without per-page config
        $lang   = $this->grav['language']->getLanguage() ?: 'en';
        $locale = self::$LOCALE_MAP[$lang] ?? ($lang . '_' . strtoupper($lang));
        $ogType = (property_exists($page->header(), 'articleenabled') && $page->header()->articleenabled)
            ? 'article'
            : 'website';
        $meta['og:site_name'] = ['property' => 'og:site_name', 'content' => $this->config->get('site.title')];
        $meta['og:locale']    = ['property' => 'og:locale',    'content' => $locale];
        $meta['og:type']      = ['property' => 'og:type',      'content' => $ogType];
        $meta['og:url']       = ['property' => 'og:url',       'content' => $this->grav['page']->canonical(true)];
        $meta['og:title']     = ['property' => 'og:title',     'content' => ($facebookEnabled ? $page->header()->facebooktitle ?? null : null) ?? $page->title()];
        $fbdesc = ($facebookEnabled && isset($page->header()->facebookdesc))
            ? substr($this->cleanMarkdown($page->header()->facebookdesc), 0, 320)
            : $cleanedMarkdown;
        $meta['og:description'] = ['property' => 'og:description', 'content' => $fbdesc];

        // Image: per-page pick → page media → global fallback
        if ($facebookEnabled && isset($page->header()->facebookimg)) {
            $imagedata = $this->seoGetimage($page->header()->facebookimg);
            $meta['og:image'] = ['property' => 'og:image', 'content' => $this->grav['uri']->base() . $imagedata['url']];
        } elseif (!empty($page->media()->images())) {
            $images = $page->media()->images();
            $first  = array_shift($images);
            $meta['og:image'] = ['property' => 'og:image', 'content' => $this->grav['uri']->base() . $first->url()];
        } elseif (!empty($this->config['plugins']['seo']['default_social_image'])) {
            $meta['og:image'] = ['property' => 'og:image', 'content' => $this->resolvePublicUrl($this->config['plugins']['seo']['default_social_image'])];
        }

        // Facebook-specific extras — only when explicitly enabled per page
        if ($facebookEnabled) {
            if (isset($config['facebookid'])) {
                $meta['fb:app_id'] = ['property' => 'fb:app_id', 'content' => $config->facebookid];
            }
            if (isset($page->header()->facebookauthor)) {
                $meta['article:author'] = ['property' => 'article:author', 'content' => $page->header()->facebookauthor];
            }
        }

        return $meta;
    }

    public function onOutputGenerated()
    {
        $inject = '';
        if (!empty($this->canonicalUrl)) {
            $inject .= PHP_EOL . '<link rel="canonical" href="' . htmlspecialchars($this->canonicalUrl, ENT_QUOTES, 'UTF-8') . '">';
        }
        $inject .= $this->jsonLdOutput;
        if (empty($inject)) {
            return;
        }
        $output = &$this->grav->output;
        $output = str_replace('</head>', $inject . '</head>', $output);
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
    
}
