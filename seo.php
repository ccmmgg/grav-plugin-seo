<?php
/**
 * SEO v2.3.5
 *
 * This plugin adds an SEO Tab to every pages for managing SEO data.
 *
 * Licensed under the MIT license, see LICENSE.
 *
 * @package     SEO
 * @version     3.0
 * @link        <https://github.com/paulmassen/grav-plugin-seo>
 * @author      Paul Massendari <paul@massendari.com>
 * @copyright   2020, Paul Massendari
 * @license     <http://opensource.org/licenses/MIT>        MIT
 */

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Data\Blueprints;
use Grav\Common\Page\Pages;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Grav;
use Grav\Common\Page\Media;
use Grav\Common\Helpers\Exif;
use Grav\Common\Page\Medium\AbstractMedia;
use Grav\Common\Iterator;


/**
 * SEO Plugin
 *
 * This plugin adds an user-friendly SEO tab for your user to manage metadata tags
 * and appearance on Search Engine Results and Social Networks.
 */

class SeoPlugin extends Plugin
{
    private string $jsonLdOutput = '';

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
           // 'onPageContentRaw' => ['onPageContentRaw', 0],
          //  'onBlueprintCreated' => ['onBlueprintCreated',  0]
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
  
/**
 * Récupère les métadonnées d'une image (dimensions et URL)
 * 
 * @param string|null $imageUrl URL de l'image à analyser
 * @return array{width: string, height: string, url: string}
 */
private function seoGetImage(?string $imageUrl): array
{
    // Si l'URL est vide, retourner directement les valeurs par défaut
    if (empty($imageUrl)) {
        return [
            'width' => '0',
            'height' => '0',
            'url' => '',
        ];
    }

    try {
        // Extraction du chemin et du nom de fichier
        if (!preg_match('~((\/[^\/]+)+)\/([^\/]+)~', $imageUrl, $matches)) {
            throw new \RuntimeException('Format d\'URL invalide');
        }

        $imagePath = $matches[1];
        $imageName = $matches[3];

        // Récupération de la page
        $page = $this->grav['page']->find($imagePath);
        if (!$page) {
            throw new \RuntimeException("Page non trouvée: $imagePath");
        }

        // Vérification de la présence d'images
        $images = $page->media()->images();
        if (empty($images)) {
            throw new \RuntimeException("Aucune image trouvée");
        }

        // Recherche de l'image spécifique
        $availableImages = array_keys($images);
        $imageIndex = array_search($imageName, $availableImages);
        if ($imageIndex === false) {
            throw new \RuntimeException("Image spécifique non trouvée");
        }

        $imageKey = $availableImages[$imageIndex];
        $image = $images[$imageKey];
        
        // Vérification du chemin de l'image
        if (!$image || !$image->path() || !file_exists($image->path())) {
            throw new \RuntimeException("Fichier image invalide ou inaccessible");
        }

        $dimensions = @getimagesize($image->path());
        if ($dimensions === false) {
            throw new \RuntimeException("Impossible de lire les dimensions de l'image");
        }

        return [
            'width' => (string)$dimensions[0],
            'height' => (string)$dimensions[1],
            'url' => $image->url(),
        ];

    } catch (\Exception $e) {
        // Log l'erreur mais ne casse pas le site
        $this->grav['log']->debug('SEO Plugin - Image Warning: ' . $e->getMessage());
        
        return [
            'width' => '0',
            'height' => '0',
            'url' => '',
        ];
    }
}
    /**
 * Nettoie et convertit le texte Markdown en texte brut
 * 
 * @param string $text Le texte Markdown à nettoyer
 * @param int $maxLength Longueur maximale du texte retourné (défaut: 320)
 * @return string Le texte nettoyé
 */
    private const MARKDOWN_RULES = [
        // Suppression des inclusions Twig
        '/{%[\s\S]*?%}[\s\S]*?/' => '',
        
        // Suppression des balises HTML spécifiques
        '/<style[^>]*?>.*?<\/style>/si' => '',
        '/<script[^>]*?>.*?<\/script>/si' => '',
        
        // Conversion de la syntaxe Markdown
        '/^#+\s*(.*)$/m' => '$1',                  // Titres
        '/^[*\-_]{3,}$/m' => '',                   // Lignes horizontales
        '/!\[([^\]]*)\]\([^)]+\)/' => '',          // Images
        '/\[([^\]]+)\]\([^)]+\)/' => '$1',         // Liens
        '/[*_]{2}(.*?)[*_]{2}/' => '$1',           // Gras
        '/[*_](.*?)[*_]/' => '$1',                 // Italique
        '/~~(.*?)~~/' => '$1',                     // Barré
        '/:`(.*?)`/' => '$1',                      // Code inline
        '/^```[\s\S]*?```$/m' => '',               // Blocs de code
        '/^[*\-+]\s+(.*)$/m' => '$1',              // Listes non ordonnées
        '/^\d+\.\s+(.*)$/m' => '$1',               // Listes ordonnées
        '/^>\s*(.*)$/m' => '$1',                   // Citations
        '/<!--[\s\S]*?-->/' => '',                 // Commentaires HTML
    ];

    private function cleanMarkdown(string $text, int $maxLength = 320): string 
{

    // Nettoyage initial
    $text = strip_tags($text);

    // Application des règles de nettoyage Markdown
    foreach (self::MARKDOWN_RULES as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
    }

    // Nettoyage final
    $text = preg_replace('/\s+/', ' ', $text);           // Remplace les espaces multiples
    $text = str_replace(["\r", "\n"], ' ', $text);       // Remplace les retours à la ligne
    $text = preg_replace('/\. \./', '.', $text);         // Corrige la ponctuation
    $text = trim($text);                                 // Supprime les espaces aux extrémités

    // Retourne le texte tronqué à la longueur maximale
    return mb_substr($text, 0, $maxLength);
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

        // Set admin specific events
        if ($this->isAdmin()) {
            $this->active = false;
            $events = [
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onBlueprintCreated' => ['onBlueprintCreated', 0],
               // 'onPageContentRaw' => ['onPageContentRaw', 0],
            ];
        }

        // Register events
  
        $this->enable($events);
    }
    public function onPageInitialized()
    {
        $page = $this->grav['page'];
        $config = $this->mergeConfig($page);
        $content = strip_tags($page->content());
        $cleanedMarkdown = $this->cleanMarkdown($page->content());
        $microdata = [];
        $outputjson = '';
        $outputcustomjson = '';
        $meta = $page->metadata(null);

        $meta = $this->applyGoogleMeta($page, $meta, $cleanedMarkdown);
        $meta = $this->applyTwitterMeta($page, $meta, $cleanedMarkdown, $config);
        $meta = $this->applyOpenGraphMeta($page, $meta, $cleanedMarkdown, $config);
        $page->metadata($meta);
        // Set Json-Ld Microdata
        // Article Microdata
     if (property_exists($page->header(), 'musiceventenabled')) {
    if ($page->header()->musiceventenabled && $this->config['plugins']['seo']['musicevent']) {
        $musiceventsarray = $page->header()->musicevents ?? [];
        
        // Vérifier que nous avons un array valide et non vide
        if (is_array($musiceventsarray) && !empty($musiceventsarray)) {
            foreach ($musiceventsarray as $event) {
                $performerarray = [];  // Initialiser pour chaque événement
                $workarray = [];       // Initialiser pour chaque événement
                $musiceventimage = null;  // Initialiser pour chaque événement

                // Gestion des performers
                if (!empty($event['musicevent_performer']) && is_array($event['musicevent_performer'])) {
                    foreach ($event['musicevent_performer'] as $artist) {
                        $performerarray[] = [
                            '@type' => $artist['performer_type'] ?? 'PerformingGroup',
                            'name' => $artist['name'] ?? '',
                            'sameAs' => $artist['sameAs'] ?? '',
                        ];
                    }
                }

                // Gestion des œuvres interprétées
                if (!empty($event['musicevent_workPerformed']) && is_array($event['musicevent_workPerformed'])) {
                    foreach ($event['musicevent_workPerformed'] as $work) {
                        $workarray[] = [
                            'name' => $work['name'] ?? '',
                            'sameAs' => $work['sameAs'] ?? '',
                        ];
                    }
                }

                // Gestion de l'image
                if (!empty($event['musicevent_image'])) {
                    $imagedata = $this->seoGetImage($event['musicevent_image']);
                    if (!empty($imagedata['url'])) {
                        $musiceventimage = [
                            '@type' => 'ImageObject',
                            'width' => $imagedata['width'],
                            'height' => $imagedata['height'],
                            'url' => $this->grav['uri']->base() . $imagedata['url'],
                        ];
                    }
                }

                // Construction de l'événement
                $eventData = [
                    '@context' => 'https://schema.org',
                    '@type' => 'MusicEvent',
                    'name' => $event['musicevent_location_name'] ?? '',
                    'location' => [
                        '@type' => 'MusicVenue',
                        'name' => $event['musicevent_location_name'] ?? '',
                        'address' => $event['musicevent_location_address'] ?? '',
                    ],
                    'description' => $event['musicevent_description'] ?? '',
                    'url' => $event['musicevent_url'] ?? '',
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => $event['musicevent_offers_price'] ?? '',
                        'priceCurrency' => $event['musicevent_offers_priceCurrency'] ?? '',
                        'url' => $event['musicevent_offers_url'] ?? '',
                    ],
                ];

                // Ajouter les champs optionnels seulement s'ils existent
                if (!empty($performerarray)) {
                    $eventData['performer'] = $performerarray;
                }
                if (!empty($workarray)) {
                    $eventData['workPerformed'] = $workarray;
                }
                if ($musiceventimage) {
                    $eventData['image'] = $musiceventimage;
                }

                // Gestion des dates
                if (!empty($event['musicevent_startdate'])) {
                    $eventData['startDate'] = date("c", strtotime($event['musicevent_startdate']));
                }
                if (!empty($event['musicevent_enddate'])) {
                    $eventData['endDate'] = date("c", strtotime($event['musicevent_enddate']));
                }

                $microdata[] = $eventData;
            }
        }
    }
}
       if (property_exists($page->header(), 'eventenabled')) {
    if ($page->header()->eventenabled && $this->config['plugins']['seo']['event']) {
        $eventsarray = $page->header()->addevent ?? [];
        
        // Vérifier que nous avons un array valide et non vide
        if (is_array($eventsarray) && !empty($eventsarray)) {
            foreach ($eventsarray as $event) {
                // Préparer l'adresse seulement si les données nécessaires existent
                $address = [
                    '@type' => 'PostalAddress',
                ];
                
                // Ajouter les champs d'adresse seulement s'ils existent
                if (!empty($event['event_location_address_addressLocality'])) {
                    $address['addressLocality'] = $event['event_location_address_addressLocality'];
                }
                if (!empty($event['event_location_address_addressRegion'])) {
                    $address['addressRegion'] = $event['event_location_address_addressRegion'];
                }
                if (!empty($event['event_location_streetAddress'])) {
                    $address['streetAddress'] = $event['event_location_streetAddress'];
                }

                // Préparer l'offre seulement si les données nécessaires existent
                $offers = [
                    '@type' => 'Offer',
                ];
                if (!empty($event['event_offers_price'])) {
                    $offers['price'] = $event['event_offers_price'];
                }
                if (!empty($event['event_offers_currency'])) {
                    $offers['priceCurrency'] = $event['event_offers_currency'];
                }
                if (!empty($event['event_offers_url'])) {
                    $offers['url'] = $event['event_offers_url'];
                }

                // Construction de l'événement de base
                $eventData = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Event',
                    'name' => $event['event_name'] ?? '',
                    'location' => [
                        '@type' => 'Place',
                        'name' => $event['event_location_name'] ?? '',
                        'address' => $address,
                    ],
                ];

                // Ajouter l'URL de la location si elle existe
                if (!empty($event['musicevent_location_url'])) {
                    $eventData['location']['url'] = $event['musicevent_location_url'];
                }

                // Ajouter la description si elle existe
                if (!empty($event['event_description'])) {
                    $eventData['description'] = $event['event_description'];
                }

                // Ajouter les offres si elles ne sont pas vides
                if (count(array_filter($offers)) > 1) { // > 1 car @type est toujours présent
                    $eventData['offers'] = $offers;
                }

                // Gestion des dates
                if (!empty($event['event_startDate'])) {
                    $startDate = strtotime($event['event_startDate']);
                    if ($startDate) {
                        $eventData['startDate'] = date("c", $startDate);
                    }
                }
                if (!empty($event['event_endDate'])) {
                    $endDate = strtotime($event['event_endDate']);
                    if ($endDate) {
                        $eventData['endDate'] = date("c", $endDate);
                    }
                }

                $microdata[] = array_filter($eventData, function($value) {
                    return $value !== null && $value !== '';
                });
            }
        }
    }
}
     if (property_exists($page->header(), 'personenabled')) {
    if ($page->header()->personenabled && $this->config['plugins']['seo']['person']) {
        $personarray = $page->header()->addperson ?? [];
        
        // Vérification que $personarray est un array et n'est pas vide
        if (is_array($personarray) && !empty($personarray)) {
            foreach ($personarray as $person) {
                $microdata[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Person',
                    'name' => $person['person_name'] ?? null,
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => $person['person_address_addressLocality'] ?? null,
                        'addressRegion' => $person['person_address_addressRegion'] ?? null,
                    ],
                    'jobTitle' => $person['person_jobTitle'] ?? null,
                ];
            }
        }
    }
}
        if (property_exists($page->header(),'orgaenabled')){
       if ($page->header()->orgaenabled and $this->config['plugins']['seo']['organization']) {
        $founderarray    = [];
        $similararray    = [];
        $areaservedarray = [];
        $openingHours    = [];
        $offerarray      = [];
        $orgarating      = null;

        if (isset($page->header()->orga['founders'])){
        foreach ($page->header()->orga['founders'] as $founder){
                  $founderarray[] = [
                      '@type' => 'Person',
                      'name' => $founder['name'] ?? null,
                    ];
                 }
        }
        if (isset($page->header()->orga['similar'])){
            foreach ($page->header()->orga['similar'] as $similar){
                      $similararray[] = $similar['sameas'];
                     }
        }
        if (isset($page->header()->orga['areaserved'])){
            foreach ($page->header()->orga['areaserved'] as $areaserved){
                      $areaservedarray[] = $areaserved['area'];
                     }
        }
        if (isset($page->header()->orga['openingHours'])){
            foreach ($page->header()->orga['openingHours'] as $hours){
                      $openingHours[] = $hours['entry'];
                     }
        }
        if (isset($page->header()->orga['offercatalog'])){
            foreach ($page->header()->orga['offercatalog'] as $offer) {
                if (array_key_exists('offereditem', $offer)) {
                    foreach ($offer['offereditem'] as $service) {
                        $offerarray[] = [
                            '@type' => 'OfferCatalog',
                            'name' => $offer['offer'] ?? null,
                            'description' => $offer['description'] ?? null,
                            'url' => $offer['url'] ?? null,
                            'image' => $offer['image'] ?? null,
                            'itemListElement' => [
                                '@type' => 'Offer',
                                'itemOffered' => [
                                    '@type' => 'Service',
                                    'name' => $service['name'] ?? null,
                                    'url' => $service['url'] ?? null,
                                ],
                            ],
                        ];
                    }
                } else {
                        $offerarray[] = [
                            '@type' => 'OfferCatalog',
                            'name' => $offer['offer'] ?? null,
                            'description' => $offer['description'] ?? null,
                            'url' => $offer['url'] ?? null,
                            'image' => $offer['image'] ?? null,
                        ];
                }
            }
        }

        if (property_exists($page->header(),'orgaratingenabled')){

        if ($page->header()->orgaratingenabled){
        $orgarating = [
                      '@type' => 'AggregateRating',
                      'ratingValue' => $page->header()->orga['ratingValue'] ?? null,
                      'reviewCount' => $page->header()->orga['reviewCount'] ?? null,
                      ];
        }

        }
        $orga = $page->header()->orga ?? [];
        $microdata[] = [
                  '@context' => 'https://schema.org',
                  '@type' => 'Organization',
                  'name' => $orga['name'] ?? null,
                  'legalname' => $orga['legalname'] ?? null,
                  'taxid' => $orga['taxid'] ?? null,
                  'vatid' => $orga['vatid'] ?? null,
                  'areaServed' => $areaservedarray ?: null,
                  'description' => $orga['description'] ?? null,

                  'address' => [
                      '@type' => 'PostalAddress',
                      'streetAddress' => $orga['streetaddress'] ?? null,
                      'addressLocality' => $orga['city'] ?? null,
                      'addressRegion' => $orga['state'] ?? null,
                      'postalCode' => $orga['zipcode'] ?? null,
                      ],
                  'telephone' => $orga['phone'] ?? null,
                  'logo' => $orga['logo'] ?? null,
                  'url' => $orga['url'] ?? null,
                  'openingHours' => $openingHours ?: null,
                  'email' => $orga['email'] ?? null,
                  'foundingDate' => $orga['foundingDate'] ?? null,
                  'aggregateRating' => $orgarating,
                  'paymentAccepted' => $orga['paymentAccepted'] ?? null,
                  'founders' => $founderarray ?: null,
                  'sameAs' => $similararray ?: null,
                  'hasOfferCatalog' => $offerarray ?: null,
                  ];



       }
       }
        if (property_exists($page->header(),'restaurantenabled')){
        if ($page->header()->restaurantenabled and $this->config['plugins']['seo']['restaurant']) {
         $restaurantimage = null;
         $restaurant = $page->header()->restaurant ?? [];
         if (isset($restaurant['image'])){
            $imagedata = $this->seoGetimage($restaurant['image']);
            $restaurantimage = [
                      '@type' => 'ImageObject',
                      'width' => $imagedata['width'],
                      'height' => $imagedata['height'],
                      'url' => $this->grav['uri']->base() . $imagedata['url'],
                      ];
            }
              $microdata[] = [
                  '@context' => 'https://schema.org',
                  '@type' => 'Restaurant',
                  'name' => $restaurant['name'] ?? null,
                  'address' => [
                      '@type' => 'PostalAddress',
                      'addressLocality' => $restaurant['address_addressLocality'] ?? null,
                      'addressRegion' => $restaurant['address_addressRegion'] ?? null,
                      'streetAddress' => $restaurant['address_streetAddress'] ?? null,
                      'postalCode' => $restaurant['address_postalCode'] ?? null,
                      ],
                  'areaServed' => $areaservedarray ?? null,
                  'servesCuisine' => $restaurant['servesCuisine'] ?? null,
                  'priceRange' => $restaurant['priceRange'] ?? null,
                  'image' => $restaurantimage,
                  'telephone' => $restaurant['telephone'] ?? null,
                  ];

       }
        }
    if (property_exists($page->header(),'productenabled')){
        if ($page->header()->productenabled and $this->config['plugins']['seo']['product']) {
         $product = $page->header()->product ?? [];
         $productimage = [];
         $offer = [];

         if (isset($product['image'])){
             foreach ($product['image'] as $imagearray){
                 foreach ($imagearray as $imagepath){
                     $imagedata = $this->seoGetimage($imagepath);
                     $productimage[] = $this->grav['uri']->base() . $imagedata['url'];
                 }
             }
         }
         if (isset($product['addoffer'])){
             foreach ($product['addoffer'] as $key => $offerdata){
                 $offer[$key] = [
                      '@type' => 'Offer',
                      'priceCurrency' => $offerdata['offer_priceCurrency'] ?? null,
                      'price' => $offerdata['offer_price'] ?? null,
                      'validFrom' => $offerdata['offer_validFrom'] ?? null,
                      'priceValidUntil' => $offerdata['offer_validUntil'] ?? null,
                      'availability' => $offerdata['offer_availability'] ?? null,
                     ];
             }
         }

              $microdata[] = [
                  '@context' => 'https://schema.org',
                  '@type' => 'Product',
                  'name' => $product['name'] ?? null,
                  'category' => $product['category'] ?? null,
                  'brand' => [
                      '@type' => 'Thing',
                      'name' => $product['brand'] ?? null,
                      ],
                  'offers' => $offer ?: null,
                  'description' => $product['description'] ?? null,
                  'image' => $productimage ?: null,
                  'aggregateRating' => [
                      '@type' => 'AggregateRating',
                      'ratingValue' => $product['ratingValue'] ?? null,
                      'reviewCount' => $product['reviewCount'] ?? null,
                      ]
                  ];
       }
        }
     if (property_exists($page->header(),'articleenabled')){
            if (isset($page->header()->article['headline'])){
               $headline =  $page->header()->article['headline'];
            } else {
                $headline = $page->title();
            }
       if ($page->header()->articleenabled and $this->config['plugins']['seo']['article']) {
        $microdata['article'] = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $headline,
    'mainEntityOfPage' => [
        "@type" => "WebPage",
        'url' => $this->grav['uri']->base(),
    ],
    'articleBody' => $this->cleanMarkdown($content),
    'datePublished' => date("c", strtotime($page->header()->article['datePublished'] ?? '') ?: $page->date()),
    'dateModified' => date("c", strtotime($page->header()->article['dateModified'] ?? '') ?: $page->date()),
];
        if (isset($page->header()->article['description'])) {
            $microdata['article']['description'] = $page->header()->article['description'];
           }
           else {
             $microdata['article']['description'] = substr($content,0,140); 
           };

         if (isset($page->header()->article['author'])) {
            $microdata['article']['author'] = $page->header()->article['author'];
           };
           if (isset($page->header()->article['publisher_name'])) {
            $microdata['article']['publisher']['@type'] = 'Organization';
            $microdata['article']['publisher']['name'] = $page->header()->article['publisher_name'];
           };
           if (isset($page->header()->article['publisher_logo_url'])) {
            $publisherlogourl = $page->header()->article['publisher_logo_url'];
            $imagedata = $this->seoGetimage($publisherlogourl);
            $microdata['article']['publisher']['logo']['@type'] = 'ImageObject';
            $microdata['article']['publisher']['logo']['url'] = $this->grav['uri']->base() . $imagedata['url'];
            $microdata['article']['publisher']['logo']['width'] =  $imagedata['width'];
            $microdata['article']['publisher']['logo']['height'] =  $imagedata['height'];
            
           };
           if (isset($page->header()->article['image_url'])) {
            $microdata['article']['image']['@type'] = 'ImageObject';
            $imageurl = $page->header()->article['image_url'];
            $imagedata = $this->seoGetimage($imageurl);
            $microdata['article']['image']['url'] = $this->grav['uri']->base() . $imagedata['url'];
            $microdata['article']['image']['width'] = $imagedata['width'];
            $microdata['article']['image']['height'] = $imagedata['height'];
          
            }
       }       
      };
      // Encode to json
     /*foreach ($microdata as $key => $value){
        if ($value === null){
           unset($microdata[$key]);
        }
    }*/
    // $microdata = array_map('array_filter', $microdata);
    $microdata = $this->cleanArray($microdata);
    $customjson = $page->header()->add_json ?? null;
     foreach ($microdata as $key => $value){
        
        
        $jsonscript =   PHP_EOL . '<script type="application/ld+json">' . PHP_EOL . json_encode($microdata[$key], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT ) . PHP_EOL . '</script>';
        $outputjson = $outputjson . $jsonscript;
      }
    if(!empty($customjson)){
      foreach($customjson as $json){
        $buildjson = PHP_EOL . '<script type="application/ld+json">' . PHP_EOL . $json['custom_json'] . PHP_EOL . '</script>';
        $outputcustomjson = $outputcustomjson . $buildjson ;
      }
      $outputjson = $outputjson . $outputcustomjson;
    }
          
      
      $this->grav['twig']->twig_vars['json'] = $outputjson;
      $this->grav['twig']->twig_vars['myvar'] = $outputjson;
      $this->jsonLdOutput = $outputjson;
     // return $outputjson;
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
        } else {
            $blueprint = $event['blueprint'];
        if ($blueprint->get('form/fields/tabs', null, '/')) {
            
            $blueprints = new Blueprints(__DIR__ . '/blueprints/');
            $extends = $blueprints->get($this->name);
            $blueprint->extend($extends, true);
        
        }
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
        }
        $meta['twitter:url'] = ['name' => 'twitter:url', 'property' => 'twitter:url', 'content' => $page->url(true)];
        return $meta;
    }

    private function applyOpenGraphMeta(Page $page, array $meta, string $cleanedMarkdown, $config): array
    {
        if (!property_exists($page->header(), 'facebookenable') || $page->header()->facebookenable != 'true') {
            return $meta;
        }
        $meta['og:site_name'] = ['property' => 'og:site_name', 'content' => $this->config->get('site.title')];
        $meta['og:title']     = ['property' => 'og:title', 'content' => $page->header()->facebooktitle ?? $page->title()];
        if (isset($config['facebookid'])) {
            $meta['fb:app_id'] = ['property' => 'fb:app_id', 'content' => $config->facebookid];
        }
        $meta['og:type'] = ['property' => 'og:type', 'content' => 'article'];
        $meta['og:url']  = ['property' => 'og:url',  'content' => $this->grav['page']->canonical(true)];
        $fbdesc = isset($page->header()->facebookdesc)
            ? substr($this->cleanMarkdown($page->header()->facebookdesc), 0, 320)
            : $cleanedMarkdown;
        $meta['og:description'] = ['property' => 'og:description', 'content' => $fbdesc];
        if (isset($page->header()->facebookauthor)) {
            $meta['article:author'] = ['property' => 'article:author', 'content' => $page->header()->facebookauthor];
        }
        if (isset($page->header()->facebookimg)) {
            $imagedata = $this->seoGetimage($page->header()->facebookimg);
            $meta['og:image'] = ['property' => 'og:image', 'content' => $this->grav['uri']->base() . $imagedata['url']];
        } elseif (!empty($page->media()->images())) {
            $images = $page->media()->images();
            $first  = array_shift($images);
            $meta['og:image'] = ['property' => 'og:image', 'content' => $this->grav['uri']->base() . $first->url()];
        }
        return $meta;
    }

    public function onOutputGenerated()
    {
        if (empty($this->jsonLdOutput)) {
            return;
        }
        $output = &$this->grav->output;
        $output = str_replace('</head>', $this->jsonLdOutput . '</head>', $output);
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
    
}
