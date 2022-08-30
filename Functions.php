<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2022 webtrees development team
 *					  <http://webtrees.net>
 *
 * RepositoryHierarchy (webtrees custom module):  
 * Copyright (C) 2022 Markus Hemprich
 *                    <http://www.familienforschung-hemprich.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\RepositoryHierarchyNamespace;

use DOMDocument;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Date\AbstractCalendarDate;
use Fisharebest\Webtrees\Encodings\UTF8;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;


/**
 * General functions and utils, which can be used by several classes
 */
class Functions {

    /**
     * Write DOM document to a stream
     *
     * @param DOMDocument   $dom
     * 
     * @return resource
     */
    public static function export(DOMDocument $dom) 
    {
        $stream = fopen('php://memory', 'wb+');

        if ($stream === false) {
            throw new RuntimeException('Failed to create temporary stream');
        }

        //Write xml to stream
        $bytes_written = fwrite($stream, $dom->saveXML());

        if ($bytes_written !== strlen($dom->saveXML())) {
            throw new RuntimeException('Unable to write to stream.  Perhaps the disk is full?');
        }

        if (rewind($stream) === false) {
            throw new RuntimeException('Cannot rewind temporary stream');
        }

        return $stream;
    }

    /**
     * Return response to download a file from a DOM document
     * 
     * @param string    $filename       Name of download file without extension
     *
     * @return ResponseInterface
     */
    public static function responseForDOMDownload(DOMDocument $dom, string $filename): ResponseInterface 
    {
        $resource = Functions::export($dom);
        $stream_factory = new Psr17Factory();
        $response_factory = app(ResponseFactoryInterface::class);
        $stream = $stream_factory->createStreamFromResource($resource);

         return $response_factory->createResponse()
            ->withBody($stream)
            ->withHeader('content-type', 'text/xml; charset=' . UTF8::NAME)
            ->withHeader('content-disposition', 'attachment; filename="' . addcslashes($filename, '"') . '.xml"');
    }

    /**
     * Get level 1 address tags for repositories in GEDCOM  
     * 
     * @return array    [string with address tags]
     */
    public static function getGedcomAddressTags(int $level): array
    {
        switch($level) {

            case 1:
                return [
                    'REPO:ADDR',
                    'REPO:PHON',
                    'REPO:EMAIL',
                    'REPO:FAX',
                    'REPO:WWW',
                ];

            case 2:
                return [
                    'ADR1',
                    'ADR2',
                    'ADR3',
                    'CITY',
                    'STAE',
                    'POST',
                    'CTRY',
                ];

            default:
                return [];                
        }
    }

    /**
     * Get address lines of a repository 
     * 
     * @param Repository    $repository
     * 
     * @return array    [string with address line]
     */
    public static function getRepositoryAddressLines(Repository $repository): array
    {
        $address_lines = [];
        $level1_address_tags = self::getGedcomAddressTags(1);
        $level2_address_tags = self::getGedcomAddressTags(2);     

        foreach($repository->facts() as $fact) {

            if (in_array($fact->tag(), $level1_address_tags)) {
                $address_lines[$fact->tag()] = $fact->value();       
            }

            if ($fact->tag() === 'REPO:ADDR') {

                foreach($level2_address_tags as $tag) {

                    if($fact->attribute($tag) !== '') {
                        $address_lines[$tag] = $fact->attribute($tag);
                    }
                }
            }
        }

        return $address_lines;
    }

    /**
     * Source value by tag
     * 
     * @param Source    $source
     * 
     * @return array    [$tag => $value]
     */
    public static function sourceValuesByTag(Source $source, Repository $repository = null): array
    {
        $source_values = [];
        $level1_source_tags = [
            'SOUR:DATA',
            'SOUR:AUTH',
            'SOUR:TITL',
            'SOUR:ABBR',
            'SOUR:PUBL',
            'SOUR:TEXT',
            'SOUR:REPO',
            'SOUR:REFN',
            'SOUR:RIN',
            'SOUR:NOTE',
        ];

        foreach($source->facts() as $fact) {

            if (in_array($fact->tag(), $level1_source_tags )) {
 
                $source_values[$fact->tag()] = $fact->value();              
                
                switch($fact->tag()) {
                    case 'SOUR:REPO':

                        //If repository is provided, do not continue if it doesn't match
                        if (isset($repository)) {
                            if ($fact->value() !== '@'. $repository->xref() . '@') {
                                break;
                            }
                        }

                        //Get call number
                        if($fact->attribute('CALN') !== '') {
                            $source_values['SOUR:REPO:CALN'] = $fact->attribute('CALN');
                        }
                        break;

                    case 'SOUR:DATA':
                        //Get date range
                        $date_range_text = self::displayISODateRangeForSource($source);

                        if($date_range_text !== '') {
                            $source_values['SOUR:DATA:EVEN:DATE'] = $date_range_text;
                        }
                        break;

                    case 'SOUR:REFN':
                        //Get reference number type
                        if($fact->attribute('TYPE') !== '') {
                            $source_values['SOUR:REFN:TYPE'] = $fact->attribute('TYPE');
                        }
                        break;                    
                }
            }
        }

        //Substitue characters, which cause errors in XML/HTML
        foreach($source_values as $key=>$value) {
            $source_values[$key] = e($value);
            //$source_values[$key] = htmlspecialchars($value, ENT_XML1, 'UTF-8');
        }

        return $source_values;
    }

    /**
     * Display the date range for a source
     *
	 * @param Source    $source 
     * @param Tree      $tree
     * @param string    $date format
     *
     * @return string
     */
    public static function displayDateRangeForSource(Source $source, Tree $tree = null, string $date_format = null): string {	
	
        $date_range = self::getDateRangeForSource($source);

        if(($date_range !== null) && $date_range->isOK()) {
            return $date_range->display($tree, $date_format);
        } else {
            return '';
        }
    }

    /**
     * Display the date range for a source in ISO format 
     *
	 * @param Source    $source 
     *
     * @return string
     */
    public static function displayISODateRangeForSource(Source $source): string {	
	
        $date_range = self::getDateRangeForSource($source);

        if(($date_range !== null) && $date_range->isOK()) 
        {
            return self::getISOformatForDateRange($date_range);
        }
        
        return '';
    }    

    /**
     * Display a date range in ISO format 
     *
	 * @param Date  $date_range
     *
     * @return string
     */
    public static function getISOformatForDateRange(Date $date_range): string {	

        if(($date_range !== null) && $date_range->isOK()) 
        {
            $date_range_text = $date_range->display(null, '%Y-%m-%d');
            $date_range_text = self::removeHtmlTags($date_range_text);
            $date_range_text = str_replace(' ', '', $date_range_text);
            $date_range_text = str_replace(I18N::translateContext('Start of date range', 'From'), '', $date_range_text); 
            $date_range_text = str_replace(I18N::translateContext('End of date range', 'To'), '/', $date_range_text); 
            
            $patterns = [
                '/\A(\d+)\/\Z/',            //  1659/
                '/\A\/(\d+)\Z/',            //  /1659
                '/\A(\d\d\d)\/(.*)/',       //  873/*
                '/\A(\d\d)\/(.*)/',         //  87/*
                '/\A(\d)\/(.*)/',           //  7/*
                '/\A(\d\d\d)-(.+?)\/(.*)/', //  873-*/*
                '/\A(\d\d)-(.+?)\/(.*)/',   //  87-*/*
                '/\A(\d)-(.+?)\/(.*)/',     //  7-*/*
                '/(.*)\/(\d\d\d)\Z/',       //  */873
                '/(.*)\/(\d\d)\Z/',         //  */87
                '/(.*)\/(\d)\Z/',           //  */8
                '/(.*)\/(\d\d\d)-(.+)/',    //  */873-
                '/(.*)\/(\d\d)-(.+)/',      //  */87-
                '/(.*)\/(\d)-(.+)/',        //  */8-
            ];
            $replacements = [
                '$1',                       //  1659/
                '$1',                       //  /1659
                '0$1/$2',                   //  873/*
                '00$1/$2',                  //  87/*
                '000$1/$2',                 //  8/*
                '0$1-$2/$3',                //  873-*/*
                '00$1-$2/$3',               //  87-*/*
                '000$1-$2/$3',              //  8-*/*
                '$1/0$2',                   //  */873
                '$1/00$2',                  //  */87
                '$1/000$2',                 //  */8
                '$1/0$2/$3',                //  */873-
                '$1/00$2/$3',               //  */87-
                '$1/000$2/$3',              //  */8-
            ];
            
            return preg_replace($patterns, $replacements, $date_range_text);             
        }

        return '';
    }	

    /**
     * Get places for a source
     *
	 * @param Source
     *
     * @return array
     */
    public static function getPlacesForSource(Source $source): array {	
			
        $places = [];

        if ($source->facts(['DATA'])->isNotEmpty() ) {

            $data = $source->facts(['DATA'])->first(); 	

            preg_match_all('/3 PLAC (.{1,32})/', $data->gedcom(), $matches, PREG_SET_ORDER);
            
            if (!empty($matches[0]) ) {
                $match = $matches[0];
                array_push($places, $match[1]);               
            }       
        }

        return $places;
    }

    /**
     * Validate whether a string is an URL
     *
     * @param string  $url 
     * 
     * @return  bool
     */
    public static function validateWhetherURL(string $url): bool {
        $path = parse_url($url, PHP_URL_PATH);
        $encoded_path = array_map('urlencode', explode('/', $path));
        $url = str_replace($path, implode('/', $encoded_path), $url);
    
        return filter_var($url, FILTER_VALIDATE_URL) ? true : false;
    }
    
    /**
     * Remove html tags
     *
     * @param string  $text 
     * 
     * @return string
     */
    public static function removeHtmlTags(string $text): string {
            return preg_replace('/<[a-z]+[^<>]+?>([^<>]+?)<\/[a-z]+?>/', '$1', $text);
    }

    /**
     * Get the date range for a source
     *
	 * @param Source
     *
     * @return Date
     */
    public static function getDateRangeForSource(Source $source): ?Date {	
			
        $dates = [];
        $dates_found = 0;

        if ($source->facts(['DATA'])->isNotEmpty() ) {

            foreach($source->facts(['DATA']) as $data) {

                preg_match_all('/3 DATE (.{1,32})/', $data->gedcom(), $matches, PREG_PATTERN_ORDER);
                
                foreach($matches[1] as $match) {
                    array_push($dates, new Date($match));
                    $dates_found++;
                }       
            }
        }

        $date_range = Functions::getOverallDateRange($dates);

        return ($dates_found > 0) ? $date_range : null;
    }

    /**
     * Get overall date range for a set of date ranges, i.e. minimum and maximum dates of all the date ranges
     *
	 * @param array [Date]
     *
     * @return Date
     */
    public static function getOverallDateRange(array $dates): ?Date {	

        $dates_found = 0;

        foreach($dates as $date) {

            $dates_found++;

            //Calclulate new max/min values for date range if more than one date is found
            if ($dates_found > 1) {

                if(AbstractCalendarDate::compare($date->minimumDate(), $date_range->minimumDate()) < 1) {
                    $min_date = $date->minimumDate();
                } else {
                    $min_date = $date_range->minimumDate();
                }
                if(AbstractCalendarDate::compare($date->maximumDate(), $date_range->maximumDate()) > 0) {
                    $max_date = $date->maximumDate();
                } else {
                    $max_date = $date_range->maximumDate();
                }

                $date_range = new Date('FROM ' . $min_date->format('%A %O %E') . ' TO ' . $max_date->format('%A %O %E') );

            } else {
                $date_range = $date;
            }
        }    

        return ($dates_found > 0) ? $date_range : null; 
    }

    /**
     * Get collection for an array
     *
	 * @param array $items
     *
     * @return Collection
     */
    public static function getCollectionForArray(array $items): Collection {
		
        $collection = new Collection();
        
        foreach($items as $item){
                $collection->push($item);
        }

        return $collection;
    }

    /**
     * Sorting sources by call number
     *
	 * @param Collection    $sources
	 * @param Repository    $repository
     *
     * @return Collection
     */
    public static function sortSourcesByCallNumber(Collection $sources): Collection {

        return $sources->sort(function (Source $source1, Source $source2) {
            return strnatcmp(self::getCallNumberForSource($source1), self::getCallNumberForSource($source2));
        });
    }

    /**
     * Sorting call number categories by call number
     *
	 * @param Collection $categories
     *
     * @return Collection
     */
    public static function sortCallNumberCategoriesByName(Collection $categories): Collection {
		
        return $categories->sort(function (CallNumberCategory $category1, CallNumberCategory $category2) {
                return strnatcmp($category1->getFullName(), $category2->getFullName());
            });
    }

    /**
     * Get call number for a source
     *
	 * @param Source        $source
	 * @param Repository    $repository
     *
     * @return string
     */
    public static function getCallNumberForSource(Source $source, Repository $repository = null): string{	
	
        $source_facts = self::sourceValuesByTag($source, $repository);

        return isset($source_facts['SOUR:REPO:CALN']) ? $source_facts['SOUR:REPO:CALN'] : '';
	}

    /**
     * Get xref of default repository
     * 
     * @param Tree
     * @param UserInterface $user
     *
     * @return string
     */
    public static function getDefaultRepositoryXref(AbstractModule $module, Tree $tree, UserInterface $user): string 
    {
        Auth::checkComponentAccess($module, ModuleListInterface::class, $tree, $user);

        $repositories = DB::table('other')
            ->where('o_file', '=', $tree->id())
            ->where('o_type', '=', Repository::RECORD_TYPE)
            ->get()
            ->map(Registry::repositoryFactory()->mapper($tree))
            ->filter(GedcomRecord::accessFilter());
        
        foreach ($repositories as $repository) {
            return $repository->xref();
        }   
        return '';
    }


}
