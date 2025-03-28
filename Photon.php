<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Photon;

use Geocoder\Collection;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Location;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Photon\Model\PhotonAddress;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Psr\Http\Client\ClientInterface;

/**
 * @author Niklas Närhinen <niklas@narhinen.net>
 * @author Jonathan Beliën <jbe@geo6.be>
 */
final class Photon extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    private $rootUrl;

    /**
     * @param ClientInterface $client an HTTP client
     */
    public static function withKomootServer(ClientInterface $client): self
    {
        return new self($client, 'https://photon.komoot.io');
    }

    /**
     * @param ClientInterface $client  an HTTP client
     * @param string          $rootUrl Root URL of the photon server
     */
    public function __construct(ClientInterface $client, $rootUrl)
    {
        parent::__construct($client);

        $this->rootUrl = rtrim($rootUrl, '/');
    }

    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();

        // This API doesn't handle IPs
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Photon provider does not support IP addresses.');
        }

        $url = $this->rootUrl
            .'/api?'
            .http_build_query([
                'q' => $address,
                'limit' => $query->getLimit(),
                'lang' => $query->getLocale(),
                'lat' => $query->getData('lat'),
                'lon' => $query->getData('lon'),
            ]);
        $url .= $this->buildLayerFilterQuery($query->getData('layer'));
        $osmTagFilters = $this->buildOsmTagFilterQuery($query->getData('osm_tag'));
        if (!empty($osmTagFilters)) {
            $url .= $osmTagFilters;
        }
        $bboxQueryString = $this->buildBboxFilterQuery($query);
        if (!is_null($bboxQueryString)) {
            $url .= $bboxQueryString;
        }

        $json = $this->executeQuery($url);

        if (!isset($json->features) || empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $results[] = $this->featureToAddress($feature);
        }

        return new AddressCollection($results);
    }

    public function reverseQuery(ReverseQuery $query): Collection
    {
        $coordinates = $query->getCoordinates();

        $longitude = $coordinates->getLongitude();
        $latitude = $coordinates->getLatitude();

        $url = $this->rootUrl
            .'/reverse?'
            .http_build_query([
                'lat' => $latitude,
                'lon' => $longitude,
                'radius' => $query->getData('radius'),
                'limit' => $query->getLimit(),
                'lang' => $query->getLocale(),
            ]);
        $url .= $this->buildLayerFilterQuery($query->getData('layer'));
        $osmTagFilters = $this->buildOsmTagFilterQuery($query->getData('osm_tag'));
        if (!empty($osmTagFilters)) {
            $url .= $osmTagFilters;
        }

        $json = $this->executeQuery($url);

        if (!isset($json->features) || empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $results[] = $this->featureToAddress($feature);
        }

        return new AddressCollection($results);
    }

    private function featureToAddress(\stdClass $feature): Location
    {
        $builder = new AddressBuilder($this->getName());

        $coordinates = $feature->geometry->coordinates;
        $properties = $feature->properties;

        $builder->setCoordinates(floatval($coordinates[1]), floatval($coordinates[0]));

        $builder->setStreetName($properties->street ?? null);
        $builder->setStreetNumber($properties->housenumber ?? null);
        $builder->setPostalCode($properties->postcode ?? null);
        $builder->setLocality($properties->city ?? null);
        $builder->setCountry($properties->country ?? null);
        $builder->setCountryCode($properties->countrycode ?? null);

        if (isset($properties->extent)) {
            $builder->setBounds($properties->extent[0], $properties->extent[2], $properties->extent[1], $properties->extent[3]);
        }

        /** @var PhotonAddress $address */
        $address = $builder->build(PhotonAddress::class);

        $address = $address
            ->withOSMId($properties->osm_id ?? null)
            ->withOSMType($properties->osm_type ?? null)
            ->withOSMTag(
                $properties->osm_key ?? null,
                $properties->osm_value ?? null
            )
            ->withName($properties->name ?? null)
            ->withState($properties->state ?? null)
            ->withCounty($properties->county ?? null)
            ->withDistrict($properties->district ?? null)
            ->withType($properties->type ?? null);

        return $address;
    }

    public function getName(): string
    {
        return 'photon';
    }

    /**
     * @param string|string[]|null $layers
     */
    private function buildLayerFilterQuery(mixed $layers): string
    {
        $query = '';
        if (null === $layers) {
            return $query;
        }
        if (is_string($layers)) {
            return '&layer='.urlencode($layers);
        }
        foreach ($layers as $layer) {
            $query .= '&layer='.urlencode($layer);
        }

        return $query;
    }

    /**
     * @param string|array<int, string>|null $filters
     */
    private function buildOsmTagFilterQuery($filters): string
    {
        $query = '';
        if (null === $filters) {
            return $query;
        }
        if (is_string($filters)) {
            return '&osm_tag='.urlencode($filters);
        }
        foreach ($filters as $filter) {
            $query .= '&osm_tag='.urlencode($filter);
        }

        return $query;
    }

    private function buildBboxFilterQuery(GeocodeQuery $query): ?string
    {
        if (null === $query->getBounds()) {
            return null;
        }

        return '&bbox='.sprintf('%f,%f,%f,%f',
            $query->getBounds()->getWest(),
            $query->getBounds()->getSouth(),
            $query->getBounds()->getEast(),
            $query->getBounds()->getNorth()
        );
    }

    private function executeQuery(string $url): \stdClass
    {
        $content = $this->getUrlContents($url);

        $json = json_decode($content);

        // API error
        if (is_null($json)) {
            throw InvalidServerResponse::create($url);
        }

        return $json;
    }
}
