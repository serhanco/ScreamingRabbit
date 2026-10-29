<?php

namespace App\Audit;

/**
 * Checks structured data entities against Google's rich result requirements (developers.google.com/search/docs/
 * appearance/structured-data): required properties keep a page out of rich results when missing, recommended ones
 * only weaken it. Subtypes (Dentist, BlogPosting, …) are checked as their parent type.
 */
class SchemaRules
{
    /**
     * type => [required, recommended]. A required entry "a|b" means one of them is enough.
     *
     * @var array<string, array{0: array<int, string>, 1: array<int, string>}>
     */
    public const RULES = [
        'Article' => [[], ['headline', 'image', 'author', 'datePublished', 'dateModified']],
        'Product' => [['name', 'offers|review|aggregateRating'], ['image', 'description', 'brand', 'sku']],
        'Offer' => [['price|priceSpecification'], ['priceCurrency', 'availability', 'url']],
        'AggregateRating' => [['ratingValue', 'ratingCount|reviewCount'], ['bestRating']],
        'Review' => [['author', 'reviewRating'], ['datePublished']],
        'BreadcrumbList' => [['itemListElement'], []],
        'ListItem' => [['position'], ['name|item']],
        'FAQPage' => [['mainEntity'], []],
        'Question' => [['name', 'acceptedAnswer|suggestedAnswer'], []],
        'Answer' => [['text'], []],
        'LocalBusiness' => [['name', 'address'], ['telephone', 'url', 'image', 'openingHoursSpecification|openingHours', 'geo', 'priceRange']],
        'Organization' => [[], ['name', 'url', 'logo', 'sameAs']],
        'Event' => [['name', 'startDate', 'location'], ['endDate', 'eventStatus', 'image', 'description', 'offers', 'organizer']],
        'Recipe' => [['name', 'image'], ['author', 'datePublished', 'description', 'recipeIngredient', 'recipeInstructions', 'totalTime']],
        'VideoObject' => [['name', 'thumbnailUrl', 'uploadDate'], ['description', 'duration', 'contentUrl|embedUrl']],
        'JobPosting' => [['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation|jobLocationType'], ['validThrough', 'employmentType', 'baseSalary']],
    ];

    /** Subtypes people actually use, mapped to the type whose rules apply. */
    public const PARENTS = [
        'NewsArticle' => 'Article', 'BlogPosting' => 'Article', 'TechArticle' => 'Article', 'ScholarlyArticle' => 'Article', 'Report' => 'Article',
        'MedicalOrganization' => 'Organization', 'Corporation' => 'Organization', 'NGO' => 'Organization', 'EducationalOrganization' => 'Organization',
        'Dentist' => 'LocalBusiness', 'MedicalClinic' => 'LocalBusiness', 'MedicalBusiness' => 'LocalBusiness', 'Physician' => 'LocalBusiness',
        'Hospital' => 'LocalBusiness', 'Pharmacy' => 'LocalBusiness', 'Optician' => 'LocalBusiness', 'VeterinaryCare' => 'LocalBusiness',
        'Restaurant' => 'LocalBusiness', 'FoodEstablishment' => 'LocalBusiness', 'CafeOrCoffeeShop' => 'LocalBusiness', 'Bakery' => 'LocalBusiness',
        'Store' => 'LocalBusiness', 'HealthAndBeautyBusiness' => 'LocalBusiness', 'BeautySalon' => 'LocalBusiness', 'DaySpa' => 'LocalBusiness',
        'HairSalon' => 'LocalBusiness', 'LegalService' => 'LocalBusiness', 'Attorney' => 'LocalBusiness', 'ProfessionalService' => 'LocalBusiness',
        'AutoRepair' => 'LocalBusiness', 'AutomotiveBusiness' => 'LocalBusiness', 'RealEstateAgent' => 'LocalBusiness', 'Hotel' => 'LocalBusiness',
        'LodgingBusiness' => 'LocalBusiness', 'HomeAndConstructionBusiness' => 'LocalBusiness', 'FinancialService' => 'LocalBusiness',
        'SportsActivityLocation' => 'LocalBusiness', 'ExerciseGym' => 'LocalBusiness', 'EducationalOrganization' => 'Organization',
        'AggregateOffer' => 'Offer', 'ProductGroup' => 'Product', 'Car' => 'Product',
    ];

    /** Types a page should carry once; a second copy usually means two plugins (or theme + plugin) print the same thing. */
    public const SINGLE = ['Organization', 'LocalBusiness', 'WebSite', 'BreadcrumbList', 'Article', 'Product', 'FAQPage', 'Recipe', 'Event', 'JobPosting'];

    /** The type whose rules apply, or null when Google has no rich result for it. */
    public static function ruleType(string $type): ?string
    {
        $type = self::PARENTS[$type] ?? $type;

        return isset(self::RULES[$type]) || in_array($type, self::SINGLE, true) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $entity  one JSON-LD node
     * @return array{type: string, required: array<int, string>, recommended: array<int, string>}|null missing properties
     */
    public static function check(array $entity): ?array
    {
        foreach ((array) ($entity['@type'] ?? []) as $type) {
            $rule = self::RULES[self::ruleType(self::shortType((string) $type)) ?? ''] ?? null;
            if ($rule === null) {
                continue;
            }
            $missing = fn (array $props) => array_values(array_filter($props, fn (string $alternatives) => ! collect(explode('|', $alternatives))
                ->contains(fn (string $prop) => self::present($entity[$prop] ?? null))));
            $required = $missing($rule[0]);
            $recommended = $missing($rule[1]);

            return $required === [] && $recommended === [] ? null : ['type' => self::shortType((string) $type), 'required' => $required, 'recommended' => $recommended];
        }

        return null;
    }

    public static function shortType(string $type): string
    {
        return preg_replace('#^https?://schema\.org/#', '', $type);
    }

    private static function present(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }
}
