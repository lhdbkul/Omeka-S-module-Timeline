<?php declare(strict_types=1);

namespace Timeline\Site\BlockLayout;

use Common\Stdlib\PsrMessage;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\AssetRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\HtmlPurifier;

class TimelineExhibit extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/block-layout/timeline-exhibit';

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var HtmlPurifier
     */
    protected $htmlPurifier;

    /**
     * @var string
     */
    protected $localPath;

    /**
     * @var string
     */
    protected $startDateProperty;

    /**
     * Copied:
     * @see \Timeline\Site\BlockLayout\TimelineExhibit
     * @see \Timeline\Mvc\Controller\Plugin\TimelineExhibitData
     *
     * @var array
     */
    protected $slideDefault = [
        // Main resource to display: one of next three media.
        'resource' => null,
        'asset' => null,
        'external' => null,
        // Default type is empty, that means "event".
        'type' => 'event',
        'start_date' => '',
        'start_display_date' => '',
        'end_date' => '',
        'end_display_date' => '',
        'display_date' => '',
        'headline' => '',
        'html' => '',
        'caption' => '',
        'credit' => '',
        // Background resource to display: one of next three media.
        'background_resource' => null,
        'background_asset' => null,
        'background_external' => null,
        'background_color' => '',
        'group' => '',
    ];

    /**
     * @param HtmlPurifier $htmlPurifier
     */
    public function __construct(
        ApiManager $api,
        HtmlPurifier $htmlPurifier,
        ?string $localPath
    ) {
        $this->api = $api;
        $this->htmlPurifier = $htmlPurifier;
        $this->localPath = $localPath;
    }

    public function getLabel()
    {
        return 'Timeline Exhibit'; // @translate
    }

    public function prepareForm(PhpRenderer $view): void
    {
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/asset-form.css', 'Omeka'))
            ->appendStylesheet($assetUrl('css/timeline-admin.css', 'Timeline'));
        $view->headScript()
            ->appendFile($assetUrl('js/asset-form.js', 'Omeka'))
            ->appendFile($assetUrl('js/timeline-admin.js', 'Timeline'));
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData();

        if (!isset($data['slides'])) {
            $data['slides'] = [];
        }

        $data['scale'] = $data['scale'] === 'cosmological' ? 'cosmological' : 'human';

        // In some cases, the ArrayTextarray store values as string.
        $eras = $data['eras'] ?? [];
        if (empty($eras)) {
            $data['eras'] = [];
        } elseif (is_string($eras)) {
            $arrayTextarea = new \Omeka\Form\Element\ArrayTextarea();
            $arrayTextarea->setAsKeyValue(true);
            $data['eras'] = $arrayTextarea->stringToArray($eras);
        }

        // In some cases, the ArrayTextarray store values as string.
        $markers = $data['markers'] ?? [];
        if (empty($markers)) {
            $data['markers'] = [];
        } elseif (is_string($markers)) {
            $dataTextarea = new \Common\Form\Element\DataTextarea();
            $dataTextarea->setDataOptions([
                'heading' => null,
                'dates' => null,
                'body' => null,
            ]);
            $data['markers'] = $dataTextarea->stringToArray($markers);
        }

        // Clean all values.
        $data['slides'] = array_values(
            array_map(function ($v) {
                return array_map(function ($w) {
                    $w = trim((string) $w);
                    return strlen($w) ? $w : null;
                }, $v);
            }, $data['slides'])
        );

        // Use a file if it exists.

        $slides = null;
        if (!empty($data['spreadsheet'])) {
            $content = $this->getFileContent($data['spreadsheet'], $errorStore);
            if ($content) {
                $slides = $this->prepareSlidesFromSpreadsheet($content, $errorStore);
                if ($slides) {
                    $data['slides'] = $slides;
                }
            }
        }

        // The process to normalize values and purify html is done even for
        // spreadsheet.

        // Normalize values and purify html.
        $data['slides'] = array_map([$this, 'normalizeSlide'], $data['slides']);

        // Remove empty slides.
        $data['slides'] = array_filter($data['slides'], function ($v) {
            unset($v['type']);
            return (bool) array_filter($v);
        });

        // Reorder slides chronologically.
        $this->startDateProperty = $data['start_date_property'];
        usort($data['slides'], [$this, 'sortEvent']);

        unset($data['spreadsheet']);

        $data = $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        $services = $site->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $defaultSettings = $services->get('Config')['timeline']['block_settings']['timelineExhibit'];
        $fieldset = $formElementManager->get(\Timeline\Form\TimelineExhibitFieldset::class);

        // Updated block with new params.
        if ($block) {
            $defaultSlides = $defaultSettings['slides'][0];
            unset($defaultSettings['slides']);
            $data = $block->data() + $defaultSettings;
            foreach ($data['slides'] as &$slide) {
                $slide += $defaultSlides;
            }
            unset($slide);
        } else {
            $data = $defaultSettings;
        }

        $dataForm = [];
        foreach ($data as $key => $value) {
            // Add fields for repeatable fieldsets with multiple fields.
            // But some keys have array as values (ArrayTextarea).
            if (is_array($value) && !in_array($key, ['eras', 'markers', 'group', 'item_metadata'])) {
                $subFieldsetName = "o:block[__blockIndex__][o:data][$key]";
                if (!$fieldset->has($subFieldsetName)) {
                    continue;
                }
                /** @var \Laminas\Form\Fieldset $subFieldset */
                $subFieldset = $fieldset->get($subFieldsetName);
                $subFieldsetBaseName = $subFieldsetName . '[__' . substr($key, 0, -1) . 'Index__]';
                /** @var \Laminas\Form\Fieldset $subFieldsetBase */
                if (!$subFieldset->has($subFieldsetBaseName)) {
                    continue;
                }
                $subFieldsetBase = $subFieldset->get($subFieldsetBaseName);
                foreach (array_values($value) as $subKey => $subValue) {
                    $newSubFieldsetName = $subFieldsetName . "[$subKey]";
                    /** @var \Laminas\Form\Fieldset $newSubFieldset */
                    $newSubFieldset = clone $subFieldsetBase;
                    $newSubFieldset
                        ->setName($newSubFieldsetName)
                        ->setAttribute('data-index', $subKey);
                    $subFieldset->add($newSubFieldset);
                    foreach ($subValue as $subSubKey => $subSubValue) {
                        $elementBaseName = $subFieldsetBaseName . "[$subSubKey]";
                        $elementName = "o:block[__blockIndex__][o:data][$key][$subKey][$subSubKey]";
                        if (!$newSubFieldset->has($elementBaseName)) {
                            continue;
                        }
                        $newSubFieldset
                            ->get($elementBaseName)
                            ->setName($elementName)
                            ->setValue($subSubValue);
                        $dataForm[$elementName] = $subSubValue;
                    }
                    // $newSubFieldset->populateValues($dataForm);
                }
                $subFieldset
                    ->remove($subFieldsetBaseName)
                    ->setAttribute('data-next-index', count($value));
            } else {
                $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
            }
        }

        $fieldset->populateValues($dataForm);

        // The slides are currently filled manually (use default form).

        return $view->formCollection($fieldset);
    }

    /**
     * Prepare the view to enable the block layout render.
     *
     * Typically used to append JavaScript to the head.
     *
     * @param PhpRenderer $view
     */
    public function prepareRender(PhpRenderer $view): void
    {
        $view->headLink()
            ->appendStylesheet('//cdn.knightlab.com/libs/timeline3/latest/css/timeline.css');
        $view->headScript()
            ->appendFile('//cdn.knightlab.com/libs/timeline3/latest/js/timeline.js');
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = self::PARTIAL_NAME)
    {
        $data = $block->data();
        $data['options'] = $block->dataValue('options', '{}');
        $vars = ['block' => $block] + $data;
        return $view->partial($templateViewScript, $vars);
    }

    public function getFulltextText(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        // TODO Add resource title, description, date, etc.?
        $fulltext = '';
        foreach ($block->dataValue('slides', []) as $slide) {
            $fulltext .= ' ' . $slide['start_date']
                . ' ' . $slide['start_display_date']
                . ' ' . $slide['end_date']
                . ' ' . $slide['end_display_date']
                . ' ' . $slide['display_date']
                . ' ' . $slide['headline']
                . ' ' . $slide['html']
                . ' ' . $slide['caption']
                . ' ' . $slide['credit'];
        }
        return $fulltext;
    }

    /**
     * Compare two partial or full dates.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    protected function sortEvent($a, $b)
    {
        // There is only one title.
        if ($b['type'] === 'title') {
            return 1;
        }
        if ($a['type'] === 'title') {
            return -1;
        }

        if ($a['type'] !== $b['type']) {
            // Type is event or era.
            return ($a['type'] === 'event') ? -1 : 1;
        }

        // Prepare the date for b first.
        // strtotime() is not used, because date are partial or may be very old.
        if ($b['start_date']) {
            $dateB = $b['start_date'];
        } elseif ($this->startDateProperty && $b['resource']) {
            try {
                $resourceB = $this->api->read('resources', ['id' => $b['resource']])->getContent();
            } catch (NotFoundException $e) {
                return -1;
            }
            $dateB = $resourceB->value($this->startDateProperty);
            if (empty($dateB)) {
                return -1;
            }
            $dateB = (string) $dateB->value();
        } else {
            return -1;
        }

        // Prepare the date for a.
        if ($a['start_date']) {
            $dateA = $a['start_date'];
        } elseif ($this->startDateProperty && $a['resource']) {
            try {
                $resourceA = $this->api->read('resources', ['id' => $a['resource']])->getContent();
            } catch (NotFoundException $e) {
                return 1;
            }
            $dateA = $resourceA->value($this->startDateProperty);
            if (empty($dateA)) {
                return 1;
            }
            $dateA = (string) $dateA->value();
        } else {
            return 1;
        }

        if ($dateA == $dateB) {
            if ($a['headline'] == $b['headline']) {
                return 0;
            }
            return ($a['headline'] < $b['headline']) ? -1 : 1;
        }

        // Normalize date before comparaison to avoid issue with date before 0.
        $minusA = substr($dateA, 0, 1) === '-' ? '-' : '';
        $minusB = substr($dateB, 0, 1) === '-' ? '-' : '';
        if ($minusA && !$minusB) {
            return -1;
        } elseif (!$minusA && $minusB) {
            return 1;
        }

        // Compare each part to manage partial date. Not optimized, but used
        // only before save.

        // Make the two dates positive to simplify comparaison.
        $compare = (bool) $minusA ? -1 : 1;
        if ($compare === -1) {
            $dateA = substr($dateA, 1);
            $dateB = substr($dateB, 1);
        }

        // Compare the year. The year is always present and can be cosmological.
        $yearA = (int) strtok($dateA, '-');
        $yearB = (int) strtok($dateB, '-');
        if ($yearA !== $yearB) {
            return ($yearA < $yearB) ? -$compare : $compare;
        }

        // Only the year is compared with minus: in any year, January is before
        // February.

        $partsA = [];
        $partsB = [];
        $regex = '~^(\d+)-?(\d*)-?(\d*)T?(\d*):?(\d*):?(.*)$~';
        preg_match($regex, $dateA, $partsA);
        preg_match($regex, $dateB, $partsB);

        for ($i = 2; $i <= 6; $i++) {
            $partsA[$i] ??= '';
            $partsB[$i] ??= '';
            if ($partsA[$i] === '' && $partsB[$i] === '') {
                return 0;
            }
            if ($partsA[$i] === '') {
                return -1;
            }
            if ($partsB[$i] === '') {
                return 1;
            }
            if ($partsA[$i] !== $partsB[$i]) {
                return ($partsA[$i] < $partsB[$i]) ? -1 : 1;
            }
        }

        return 0;
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     *
     * @param string $string
     * @return string
     */
    protected function fixEndOfLine($string)
    {
        return strtr((string) $string, ["\r\n" => "\n", "\n\r" => "\n", "\r" => "\n"]);
    }

    /**
     * Check and fill all values of a slide.
     */
    protected function normalizeSlide(array $slide): array
    {
        // Simplify checks.
        $slide += $this->slideDefault;

        if (empty($slide['type'])) {
            $slide['type'] = 'event';
        }

        [$slide['resource'], $slide['asset'], $slide['external']]
            = $this->extractResourceOrAssetOrExternal($slide['resource'], $slide['asset'], $slide['external']);
        [$slide['background_resource'], $slide['background_asset'], $slide['background_external']]
            = $this->extractResourceOrAssetOrExternal($slide['background_resource'], $slide['background_asset'], $slide['background_external']);

        if ($slide['html']) {
            $slide['html'] = $this->fixEndOfLine($this->htmlPurifier->purify($slide['html']));
        }
        if ($slide['caption']) {
            $slide['caption'] = $this->fixEndOfLine($this->htmlPurifier->purify($slide['caption']));
        }
        if ($slide['credit']) {
            $slide['credit'] = $this->fixEndOfLine($this->htmlPurifier->purify($slide['credit']));
        }

        return $slide;
    }

    protected function extractResourceOrAssetOrExternal($resource, $asset, $external): array
    {
        if (is_numeric($resource)) {
            return [(int) $resource, null, null];
        }

        if (is_numeric($asset)) {
            return [null, (int) $asset, null];
        }

        // The external may be a numeric (so a resource) or asset/xxx (so an
        // asset), or an external url.
        $resourceOrAssetOrString = function ($val) {
            if (empty($val)) {
                return null;
            } elseif (is_numeric($val)) {
                try {
                    return $this->api->read('resources', ['id' => $val])->getContent();
                } catch (NotFoundException $e) {
                    return null;
                }
            } elseif (substr($val, 0, 6) === 'asset/' && is_numeric(substr($val, 6))) {
                try {
                    /** @var \Omeka\Api\Representation\AssetRepresentation $asset */
                    return $this->api->read('assets', ['id' => substr($val, 6)])->getContent();
                } catch (NotFoundException $e) {
                    return null;
                }
            }
            return $val ?: null;
        };

        $result = $resourceOrAssetOrString($external);

        if (!$result) {
            return [null, null, null];
        } elseif ($result instanceof AbstractResourceEntityRepresentation) {
            return [$result->id(), null, null];
        } elseif ($result instanceof AssetRepresentation) {
            return [null, $result->id(), null];
        } else {
            return [null, null, $result];
        }
    }

    protected function getFileContent($filepath, ErrorStore $errorStore): ?string
    {
        $isUrl = strpos($filepath, 'https:') === 0 || strpos($filepath, 'http:') === 0;
        if ($isUrl) {
            $content = file_get_contents($filepath);
            return $content ?: null;
        }
        // The file should be inside the EasyAdmin tmp directory.
        if (!$this->localPath) {
            $errorStore->addError('spreadsheet', 'A spreadsheet file path was set, but the Easy Admin is not enabled.'); // @translate
        } elseif (strpos($filepath, '..') !== false) {
            $errorStore->addError('spreadsheet', 'The spreadsheet file path cannot contains a double "." in its path for security.'); // @translate
        } elseif (strlen(preg_replace('/[[:cntrl:]\/\\\?<>:\*\%\|\"\'`\&\;#+\^\$]/', '', $filepath)) !== strlen($filepath)) {
            $errorStore->addError('spreadsheet', 'The spreadsheet file path contains forbidden characters.'); // @translate
        } else {
            $filepath = rtrim($this->localPath, '//') . '/' . $filepath;
            if (!file_exists($filepath) || !is_readable($filepath)) {
                $errorStore->addError('spreadsheet', 'The spreadsheet file is not readable.'); // @translate
            } elseif (!filesize($filepath)) {
                $errorStore->addError('spreadsheet', 'The spreadsheet file is empty.'); // @translate
            } else {
                return file_get_contents($filepath) ?: null;
            }
        }
        return null;
    }

    protected function prepareSlidesFromSpreadsheet(string $spreadsheet, ErrorStore $errorStore): ?array
    {
        $rows = PHP_VERSION_ID < 80100
            ? $this->rowsFromCsv($spreadsheet, $errorStore)
            : $this->rowsFromSpreadsheet($spreadsheet, $errorStore);

        if (!$rows) {
            return null;
        }

        $rows = array_values(array_filter($rows));
        if (count($rows) <= 1) {
            return [];
        }

        $columns = [
            'Year',
            'Month',
            'Day',
            'Time',
            'End Year',
            'End Month',
            'End Day',
            'End Time',
            'Display Date',
            'Headline',
            'Text',
            'Media',
            'Media Credit',
            'Media Caption',
            'Media Thumbnail',
            'Alt Text',
            'Type',
            'Group',
            'Background',
        ];
        $columns = array_combine($columns, $columns);

        // Skip next columns to avoid issues with fake empty columns.
        $rows[0] = array_slice($rows[0], 0, count($columns), true);

        if ($rows[0] !== $columns) {
            $diff = array_unique(array_merge(
                array_values(array_diff($columns, $rows[0])),
                array_values(array_diff($rows[0], $columns))
            ));
            $errorStore->addError('spreadsheet', new PsrMessage(
                'The exact list of 19 headers of a Knightlab spreadsheet should be used: check {list}.', // @ŧranslate
                ['list' => $diff]
            ));
            return null;
        }

        // Remove headers.
        unset($rows[0]);

        // Convert rows into slides.

        // The empty fields are filled in a second step when a resource is set.

        $resourceOrAssetOrString = function ($val, $index) use ($errorStore) {
            if (!is_null($val) && !is_scalar($val)) {
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The cell content is invalid: {content}', // @ŧranslate
                    ['index' => $index, 'content' => $val]
                ));
                return null;
            }
            $val = trim((string) $val);
            if (empty($val)) {
                return null;
            } elseif (is_numeric($val)) {
                try {
                    return $this->api->read('resources', ['id' => $val])->getContent();
                } catch (NotFoundException $e) {
                    $errorStore->addError('spreadsheet', new PsrMessage(
                        'Spreadsheet row #{index}: The Media "{media}" is an unknown resource.', // @ŧranslate
                        ['index' => $index, 'media' => $val]
                    ));
                    return null;
                }
            } elseif (substr($val, 0, 6) === 'asset/' && is_numeric(substr($val, 6))) {
                try {
                    /** @var \Omeka\Api\Representation\AssetRepresentation $asset */
                    return $this->api->read('assets', ['id' => substr($val, 6)])->getContent();
                } catch (NotFoundException $e) {
                    $errorStore->addError('spreadsheet', new PsrMessage(
                        'Spreadsheet row #{index}: The asset "{asset}" is unknown.', // @ŧranslate
                        ['index' => $index, 'asset' => $val]
                    ));
                    return null;
                }
            } elseif (filter_var($val, FILTER_VALIDATE_URL)) {
                return $val;
            } else {
                // Check if the value is an identifier of a resource.
                // Only "items" and "dcterms:identifier" are managed.
                // The api searches does not allow to search "resources".
                // There is no searchOne for main api manager.
                $res = $this->api->search('items', ['property' => [['property' => 'dcterms:identifier', 'type' => 'eq', 'text' => $val]], 'limit' => 1])->getContent();
                if ($res) {
                    return reset($res);
                }
            }
            // Keep string.
            return $val;
        };

        $slides = [];
        foreach ($rows as $index => $row) {
            ++$index;
            $slideHasError = false;
            $slide = $this->slideDefault;

            $resource = null;
            $asset = null;
            $res = $resourceOrAssetOrString($row['Media'], $index);
            if ($res instanceof AbstractResourceEntityRepresentation) {
                $resource = $res;
                $slide['resource'] = (int) $resource->id();
            } elseif ($res instanceof AssetRepresentation) {
                $asset = $res;
                $slide['asset'] = (int) $asset->id();
            } elseif ($res) {
                $slide['external'] = $res;
            } elseif ($row['Media']) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: column Media is invalid: {content}', // @ŧranslate
                    ['index' => $index, 'content' => $row['Media']]
                ));
            }

            if (empty($row['Type'])) {
                $slide['type'] = 'event';
            } else {
                if (in_array($row['Type'], [
                    'event',
                    'era',
                    'title',
                ])) {
                    $slide['type'] = $row['Type'];
                } else {
                    $slideHasError = true;
                    $errorStore->addError('spreadsheet', new PsrMessage(
                        'Spreadsheet row #{index}: The Type "{type}" is unmanaged.', // @ŧranslate
                        ['index' => $index, 'type' => $row['Type']]
                    ));
                }
            }

            $isSlideTitle = $slide['type'] === 'title';
            $isSlideEra = $slide['type'] === 'era';
            // $isSlideEvent = !$isSlideTitle && !$isSlideEra;

            if (empty($row['End Year']) && !empty($row['End Month'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The start month "{month}" is set, but the year is empty.', // @ŧranslate
                    ['index' => $index, 'month' => $row['End Month']]
                ));
            } elseif (empty($row['Month']) && !empty($row['Day'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The start day "{day}" is set, but the month is empty.', // @ŧranslate
                    ['index' => $index, 'day' => $row['Day']]
                ));
            } elseif (empty($row['Day']) && !empty($row['Time'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The start time "{time}" is set, but the day is empty.', // @ŧranslate
                    ['index' => $index, 'time' => $row['Time']]
                ));
            } else {
                $slide['start_date'] = $row['Year'];
                if ($row['Month']) {
                    $slide['start_date'] .= '-' . sprintf('%02d', $row['Month']);
                    if ($row['Day']) {
                        $slide['start_date'] .= '-' . sprintf('%02d', $row['Day']);
                        if ($row['Time']) {
                            $slide['start_date'] .= 'T' . $row['Time'];
                        }
                    }
                }
            }

            if (empty($row['End Year']) && !empty($row['End Month'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The end month "{month}" is set, but the year is empty.', // @ŧranslate
                    ['index' => $index, 'month' => $row['End Month']]
                ));
            } elseif (empty($row['End Month']) && !empty($row['End Day'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The end day "{day}" is set, but the month is empty.', // @ŧranslate
                    ['index' => $index, 'day' => $row['End Day']]
                ));
            } elseif (empty($row['End Day']) && !empty($row['End Time'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: The end time "{time}" is set, but the day is empty.', // @ŧranslate
                    ['index' => $index, 'time' => $row['End Time']]
                ));
            } else {
                $slide['end_date'] = $row['End Year'];
                if ($row['End Month']) {
                    $slide['end_date'] .= '-' . sprintf('%02d', $row['End Month']);
                    if ($row['End Day']) {
                        $slide['end_date'] .= '-' . sprintf('%02d', $row['End Day']);
                        if ($row['End Time']) {
                            $slide['end_date'] .= 'T' . $row['End Time'];
                        }
                    }
                }
            }

            // The date is optional for title.
            if (!$isSlideTitle && empty($row['Year'])) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: A start year is required, except for title.', // @ŧranslate
                    ['index' => $index]
                ));
            }

            // The era requires a start and an end dates.
            if ($isSlideEra && (empty($slide['start_date']) || empty($slide['end_date']))) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: A slide with type "Era" should have a stard and a end end date.', // @ŧranslate
                    ['index' => $index, 'time' => $row['End Time']]
                ));
            }

            // TODO Check if display date is divided as start/end.
            $slide['start_display_date'] = $row['Display Date'];

            $slide['headline'] = $row['Headline'];

            $slide['html'] = $row['Text'];

            // The content is now resource/asset/external above.
            // $slide['content'] = $row['Media'];

            $slide['caption'] = $row['Media Caption'];

            $slide['credit'] = $row['Media Credit'];

            // TODO Manage Alt Text.

            $res = $resourceOrAssetOrString($row['Background'], $index);
            if ($res instanceof AbstractResourceEntityRepresentation) {
                $resource = $res;
                $slide['background_resource'] = (int) $resource->id();
            } elseif ($res instanceof AssetRepresentation) {
                $asset = $res;
                $slide['background_asset'] = (int) $asset->id();
            } elseif ($res) {
                // The value may be a url or a color.
                if (mb_substr($res, 0, 4) === 'http') {
                    $slide['background_external'] = $res;
                } else {
                    $slide['background_color'] = $res;
                }
            } elseif ($row['Background']) {
                $slideHasError = true;
                $errorStore->addError('spreadsheet', new PsrMessage(
                    'Spreadsheet row #{index}: column Background is invalid: {content}', // @ŧranslate
                    ['index' => $index, 'content' => $row['Background']]
                ));
            }

            $slide['group'] = $row['Group'];

            if ($slideHasError) {
                continue;
            }

            $slides[] = $slide;
        }

        return $slides;
    }

    protected function rowsFromCsv(string $spreadsheet, ErrorStore $errorStore): ?array
    {
        // TODO The "@" avoids the deprecation notice. Replace by html_entity_decode/htmlentities.
        // Do not trim early.
        $spreadsheet = (string) @mb_convert_encoding($spreadsheet, 'HTML-ENTITIES', 'UTF-8');
        if (substr($spreadsheet, 0, 3) === chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            $spreadsheet = substr($spreadsheet, 3);
        }

        if (substr($spreadsheet, 0, 5) === 'PK' . chr(3) . chr(4) . chr(0)) {
            $errorStore->addError('spreadsheet', 'The support of OpenDocument Spreadsheet requires php version 8.1 or greater.'); // @ŧranslate
            return null;
        }

        if (mb_strpos($spreadsheet, "\t") !== false) {
            $separator = "\t";
            $enclosure = chr(0);
            $escape = chr(0);
        } else {
            $separator = ',';
            $enclosure = '"';
            $escape = '\\';
        }

        $rows = array_map(fn ($v) => array_map('trim', array_map('strval', str_getcsv($v, $separator, $enclosure, $escape))), explode("\n", $spreadsheet));

        return $this->rowsWithHeaders($rows, $errorStore);
    }

    protected function rowsFromSpreadsheet(string $spreadsheet, ErrorStore $errorStore): ?array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'spreadsheet');
        file_put_contents($tempFile, $spreadsheet);
        $mediaType = mime_content_type($tempFile);

        $mediaTypesToExtensions = [
            'application/csv' => 'csv',
            'text/csv' => 'csv',
            'text/plain' => 'csv',
            // OpenSpout does not support tsv for now.
            'text/tab-separated-values' => 'csv',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
            // Not standard, and complex format, so not officially supported.
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];

        if (!isset($mediaTypesToExtensions[$mediaType])) {
            return null;
        }

        $extension = $mediaTypesToExtensions[$mediaType];
        $oldTempFile = $tempFile;
        $tempFile .= '.' . $extension;
        rename($oldTempFile, $tempFile);

        if ($mediaType === 'text/tab-separated-values'
            || ($extension === 'csv' && mb_strpos($spreadsheet, "\t") !== false)
        ) {
            $options = new \OpenSpout\Reader\CSV\Options();
            $options->FIELD_DELIMITER = "\t";
            $options->FIELD_ENCLOSURE = chr(0);
            $reader = \OpenSpout\Reader\CSV\Reader($options);
        } else {
            $reader = \OpenSpout\Reader\Common\Creator\ReaderEntityFactory::createReaderFromFile($tempFile);
        }

        $reader->open($tempFile);

        // Only one sheet, the active one.
        /** @var \OpenSpout\Reader\ODS\Sheet $sheet */
        foreach ($reader->getSheetIterator() as $currentSheet) {
            if ($extension === 'csv'
                || ($currentSheet->isActive() && $currentSheet->isVisible())
            ) {
                $sheet = $currentSheet;
                break;
            }
        }

        $rows = [];
        $sheet ??= $currentSheet;
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        $reader->close();
        unlink($tempFile);

        return $this->rowsWithHeaders($rows, $errorStore);
    }

    protected function rowsWithHeaders(array $rows, ErrorStore $errorStore): ?array
    {
        $first = true;
        foreach ($rows as $key => $row) {
            // Normally already filtered.
            if (empty(array_filter($row))) {
                unset($rows[$key]);
                continue;
            }
            // First row is headers.
            if ($first) {
                $first = false;
                $headers = array_combine($row, $row);
                $countHeaders = count($headers);
                // Headers should not be empty and duplicates are forbidden.
                if (!$countHeaders
                    || $countHeaders !== count($row)
                ) {
                    $errorStore->addError('spreadsheet', 'Some headers are duplicated.'); // @ŧranslate
                    return null;
                }
                $rows[$key] = $headers;
                continue;
            }
            if (count($row) < $countHeaders) {
                $row = array_slice(array_merge($row, array_fill(0, $countHeaders, '')), 0, $countHeaders);
            } elseif (count($row) > $countHeaders) {
                $row = array_slice($row, 0, $countHeaders);
            }
            $rows[$key] = array_combine($headers, array_map('trim', $row));
        }

        return $rows;
    }
}
