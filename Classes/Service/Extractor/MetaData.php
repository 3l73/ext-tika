<?php
namespace ApacheSolrForTypo3\Tika\Service\Extractor;

/***************************************************************
*  Copyright notice
*
*  (c) 2010-2015 Ingo Renner <ingo@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

use TYPO3\CMS\Core\Resource;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\CommandUtility;


/**
 * A service to extract meta data from files using Apache Tika
 *
 * @author Ingo Renner <ingo@typo3.org>
 * @package ApacheSolrForTypo3\Tika\Service\Extractor
 */
class MetaData extends AbstractExtractor {

	protected $supportedFileTypes = array(
		'aiff','au','bmp','doc','docx','epub','flv','gif','htm','html','jpg',
		'jpeg','mid','mp3','msg','odf','odt','pdf','png','ppt','pptx','rtf',
		'svg','sxw','tgz','tiff','txt','wav','xls','xlsx','xml','zip'
	);


	/**
	 * Checks if the given file can be processed by this Extractor
	 *
	 * @param Resource\File $file
	 * @return boolean
	 */
	public function canProcess(Resource\File $file) {
		return in_array($file->getProperty('extension'), $this->supportedFileTypes);
	}

	/**
	 * Extracts meta data from a file using Apache Tika
	 *
	 * @param Resource\File $file
	 * @param array $previousExtractedData Already extracted/existing data
	 * @return array
	 */
	public function extractMetaData(Resource\File $file, array $previousExtractedData = array()) {
		$metaData = array();

		$localFilePath = $file->getForLocalProcessing(FALSE);
		if ($this->configuration['extractor'] == 'solr') {
			$extractedMetaData = $this->extractUsingSolr($localFilePath);
		} else {
			$extractedMetaData = $this->extractUsingTika($localFilePath);
		}

		$metaData = $this->normalizeMetaData($extractedMetaData);

		return $metaData;
	}

	/**
	 * Takes shell output from exec() and turns it into an array of key => value
	 * meta data pairs.
	 *
	 * @param array $shellOutputMetaData An array containing shell output from exec() with one line per entry
	 * @return array Array of key => value pairs of meta data
	 */
	protected function shellOutputToArray(array $shellOutputMetaData) {
		$metaData = array();

		foreach ($shellOutputMetaData as $line) {
			list($dataName, $dataValue) = explode(':', $line, 2);
			$dataValue = trim($dataValue);

			if (in_array($dataName, array('dc', 'dcterms', 'meta', 'tiff', 'xmp', 'xmpTPg'))) {
				// Dublin Core metadata and co
				$dataNamePrefix = $dataName;
				list($dataName, $dataValue) = explode(':', $dataValue, 2);
				$dataName = $dataNamePrefix . ':' . $dataName;
				$dataValue = trim($dataValue);
			}

			if (array_key_exists($dataName, $metaData)) {
				if ($metaData[$dataName] == $dataValue) {
					// first duplicate key hit, but also duplicate value
					continue;
				}

				// allow a meta data key to appear multiple times
				if (!is_array($metaData[$dataName])) {
					$metaData[$dataName] = array($metaData[$dataName]);
				}

				// but do not allow duplicate values
				if (!in_array($dataValue, $metaData[$dataName])) {
					$metaData[$dataName][] = $dataValue;
				}
			} else {
				$metaData[$dataName] = $dataValue;
			}
		}

		return $metaData;
	}

	/**
	 * Normalizes the names / keys of the meta data found.
	 *
	 * @param array $metaData An array of raw meta data from a file
	 * @return array An array with cleaned meta data keys
	 */
	protected function normalizeMetaData(array $metaData) {
		$metaDataCleaned = array();

		foreach ($metaData as $key => $value) {
			if (is_array($value)) {
				$value = implode(', ', $value);
			}

			if (empty($value)) {
				continue;
			}

			// clean / add values under alternative names
			switch ($key) {
				case 'dc:title':
				case 'title':
					$metaDataCleaned['title'] = $value;
					break;
				case 'dc:creator':
				case 'meta:author':
				case 'Author':
				case 'creator':
					$metaDataCleaned['creator'] = $value;
					break;
				case 'dc:publisher':
					$metaDataCleaned['publisher'] = $value;
					break;
				case 'height':
					$metaDataCleaned['height'] = $value;
					break;
				case 'Image Height':
					list($height) = explode(' ', $value, 2);
					$metaDataCleaned['height'] = $height;
					break;
				case 'width':
					$metaDataCleaned['width'] = $value;
					break;
				case 'Image Width':
					list($width) = explode(' ', $value, 2);
					$metaDataCleaned['width'] = $width;
					break;
				case 'Color space':
					if ($value != 'Undefined') {
						$metaDataCleaned['color_space'] = $value;
					}
					break;
				case 'Image Description':
				case 'Jpeg Comment':
				case 'subject':
					$metaDataCleaned['description'] = $value;
					break;
				case 'Headline':
					$metaDataCleaned['alternative'] = $value;
					break;
				case 'dc:subject':
				case 'meta:keyword':
				case 'Keywords':
					$metaDataCleaned['keywords'] = $value;
					break;
				case 'Copyright Notice':
					$metaDataCleaned['note'] = $value;
					break;
				case 'dcterms:created':
				case 'meta:creation-date':
				case 'Creation-Date':
					$metaDataCleaned['content_creation_date'] = strtotime($value);
					break;
				case 'Date/Time Original':
					$metaDataCleaned['content_creation_date'] = $this->exifDateToTimestamp($value);
					break;
				case 'dcterms:modified':
				case 'meta:save-date':
				case 'Last-Save-Date':
				case 'Last-Modified':
					$metaDataCleaned['content_modification_date'] = strtotime($value);
					break;
				case 'xmpTPg:NPages':
				case 'Page-Count':
					$metaDataCleaned['pages'] = $value;
					break;
				case 'xmp:CreatorTool':
					$metaDataCleaned['creator_tool'] = $value;
			}
		}

		return $metaDataCleaned;
	}

	/**
	 * Converts a date string into timestamp
	 * exiftags: 2002:09:07 15:29:52
	 *
	 * @param string $date An exif date string
	 * @return integer Unix timestamp
	 */
	protected function exifDateToTimestamp($date) {
		if (is_string($date)) {
			if (($timestamp = strtotime($date)) === -1) {
				$date = 0;
			} else {
				$date = $timestamp;
			}
		}

		return $date;
	}

	/**
	 * Extracts meta data from a given file using a local Apache Tika jar.
	 *
	 * @param string $file Absolute path to the file to extract meta data from.
	 * @return string Meta data extracted from the given file.
	 */
	protected function extractUsingTika($file) {
		$tikaCommand = CommandUtility::getCommand('java')
			. ' -Dfile.encoding=UTF8'
			. ' -jar ' . escapeshellarg(GeneralUtility::getFileAbsFileName($this->configuration['tikaPath'], FALSE))
			. ' -m'
			. ' ' . escapeshellarg($file);

		$shellOutput = array();
		exec($tikaCommand, $shellOutput);
		$metaData = $this->shellOutputToArray($shellOutput);

		$this->log('Meta Data Extraction using local Tika', array(
			'file'         => $file,
			'tika command' => $tikaCommand,
			'shell output' => $shellOutput,
			'meta data'    => $metaData
		));

		return $metaData;
	}

	/**
	 * Extracts meta data from a given file using a Solr server.
	 *
	 * @param  string $file Absolute path to the file to extract meta data from.
	 * @return string Meta data extracted from the given file.
	 */
	protected function extractUsingSolr($file) {
		// FIXME move connection building to EXT:solr
		// explicitly using "new" to bypass \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance() or
		// providing a Factory

		// EM might define a different connection than already in use by
		// Index Queue
		$solr = new \tx_solr_SolrService(
			$this->configuration['solrHost'],
			$this->configuration['solrPort'],
			$this->configuration['solrPath'],
			$this->configuration['solrScheme']
		);

		$query = GeneralUtility::makeInstance('tx_solr_ExtractingQuery', $file);
		$query->setExtractOnly();
		$response = $solr->extract($query);

		$metaData = $this->solrResponseToArray($response[1]);

		$this->log('Meta Data Extraction using Solr', array(
			'file'            => $file,
			'solr connection' => (array) $solr,
			'query'           => (array) $query,
			'response'        => $response,
			'meta data'       => $metaData
		));

		return $metaData;
	}

	/**
	 * Turns the nested Solr response into the same format as produced by a
	 * local Tika jar call
	 *
	 * @param array $metaDataResponse The part of the Solr response containing the meta data
	 * @return array The cleaned meta data, matching the Tika jar call format
	 */
	protected function solrResponseToArray(array $metaDataResponse) {
		$cleanedData = array();

		foreach ($metaDataResponse as $dataName => $dataArray) {
			$cleanedData[$dataName] = $dataArray[0];
		}

		return $cleanedData;
	}
}