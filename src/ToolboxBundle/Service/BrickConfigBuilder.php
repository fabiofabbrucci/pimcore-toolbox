<?php

namespace ToolboxBundle\Service;

use Pimcore\Model\Document\Tag\Area\Info;
use Pimcore\Translation\Translator;
use Pimcore\Templating\Renderer\TagRenderer;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

class BrickConfigBuilder
{
    /**
     * @var Translator
     */
    var $translator;

    /**
     * @var TagRenderer
     */
    var $tagRenderer;

    /**
     * @var EngineInterface
     */
    var $templating;

    /**
     * @var bool
     */
    var $hasReload = FALSE;

    /**
     * @var bool
     */
    var $documentEditableId = FALSE;

    /**
     * @var string
     */
    var $documentEditableName = '';

    /**
     * @var \Pimcore\Model\Document\Tag\Area\Info null
     */
    var $info = NULL;

    /**
     * @var array
     */
    var $configElements = [];

    /**
     * @var array
     */
    var $configParameter = [];

    /**
     * @var null
     */
    var $configWindowSize = NULL;

    /**
     * ElementBuilder constructor.
     *
     * @param Translator      $translator
     * @param TagRenderer     $tagRenderer
     * @param EngineInterface $templating
     */
    public function __construct(
        Translator $translator,
        TagRenderer $tagRenderer,
        EngineInterface $templating
    ) {

        $this->translator = $translator;
        $this->tagRenderer = $tagRenderer;
        $this->templating = $templating;
    }

    /**
     * @param       $documentEditableId
     * @param       $documentEditableName
     * @param Info  $info
     * @param array $configNode
     *
     * @return string
     */
    public function buildElementConfig($documentEditableId, $documentEditableName, Info $info, $configNode = [])
    {
        if ($info->getView()->get('editmode') === FALSE) {
            return FALSE;
        }

        $this->documentEditableId = $documentEditableId;

        $this->documentEditableName = $documentEditableName;

        $this->info = $info;

        $this->configElements = isset($configNode['configElements']) ? $configNode['configElements'] : [];

        $this->configParameter = isset($configNode['configParameter']) ? $configNode['configParameter'] : [];

        $this->configWindowSize = $this->getConfigWindowSize();

        if (empty($this->configElements)) {
            return '';
        }

        $fieldSetArgs = [
            'config_elements' => $this->parseConfigElements(),
            'document_editable_name'   => $this->documentEditableName,
            'window_size'     => $this->configWindowSize,
            'document'        => $info->getDocument()
        ];

        return $this->templating->render('@Toolbox/Admin/AreaConfig/fieldSet.html.twig', $fieldSetArgs);
    }

    private function getConfigWindowSize()
    {
        $configWindowSize = isset($this->configParameter['windowSize']) ? (string)$this->configParameter['windowSize'] : NULL;
        return !is_null($configWindowSize) ? $configWindowSize : 'small';
    }

    private function needStore($type)
    {
        return in_array($type, ['select', 'multiselect', 'additionalClasses']);
    }

    private function canHaveDynamicWidth($type)
    {
        return in_array($type, ['multihref', 'href', 'image', 'input', 'multiselect', 'numeric', 'embed', 'pdf', 'renderlet', 'select', 'snippet', 'table', 'textarea', 'video', 'wysiwyg', 'parallaximage']);
    }

    private function canHaveDynamicHeight($type)
    {
        return in_array($type, ['multihref', 'width', 'image', 'multiselect', 'embed', 'pdf', 'renderlet', 'snippet', 'textarea', 'video', 'wysiwyg', 'parallaximage']);
    }

    private function getTagConfig($type, $config)
    {
        if (is_null($config)) {
            return [];
        }

        $this->hasReload = isset($config['reload']) ? $config['reload'] === TRUE : TRUE;

        $parsedConfig = $config;

        //override reload
        $parsedConfig['reload'] = FALSE;

        //set width
        if ($this->canHaveDynamicWidth($type)) {
            $parsedConfig['width'] = isset($parsedConfig['width']) ? $parsedConfig['width'] : ($this->configWindowSize === 'large' ? 760 : 560);
        } else {
            unset($parsedConfig['width']);
        }

        //set height
        if ($this->canHaveDynamicHeight($type)) {
            $parsedConfig['height'] = isset($parsedConfig['height']) ? $parsedConfig['height'] : 200;
        } else {
            unset($parsedConfig['height']);
        }

        //check store
        if ($this->needStore($type) && isset($parsedConfig['store']) && !is_null($parsedConfig['store'])) {

            if(empty($parsedConfig['store'])) {
                throw new \Exception($type . ' (' . $this->documentEditableId . ') has no valid configured store');
            }

            $store = [];
            foreach ($parsedConfig['store'] as $k => $v) {

                if (is_array($v)) {
                    $v = $v['name'];
                }

                $store[] = [$k, $this->translator->trans($v, [], 'admin')];
            }

            $parsedConfig['store'] = $store;

        } else {
            unset($parsedConfig['store']);
        }

        return $parsedConfig;
    }

    /**
     * types: type, title, description, col_class, conditions
     *
     * @param $configElementName
     * @param $rawConfig
     *
     * @return array
     * @throws \Exception
     */
    private function getAdditionalConfig($configElementName, $rawConfig)
    {
        if (is_null($rawConfig)) {
            return [];
        }

        $config = $rawConfig;
        $defaultConfigValue = isset($config['config']['default']) ? $config['config']['default'] : NULL;

        //remove tag area config.
        unset($config['config']);

        $parsedConfig = $this->parseInternalTypes($config);

        //set edit_reload to element reload setting
        $parsedConfig['edit_reload'] = $this->hasReload;

        //set editmode hidden to false on initial state
        $parsedConfig['editmode_hidden'] = FALSE;

        //set config element name
        $parsedConfig['name'] = $configElementName;

        //set default
        $parsedConfig = $this->getSelectedValue($parsedConfig, $defaultConfigValue);

        //set conditions to empty array.
        if (!isset($parsedConfig['conditions'])) {
            $parsedConfig['conditions'] = [];
        } else if (!is_array($parsedConfig['conditions'])) {
            throw new \Exception('conditions configuration needs to be an array');
        }

        return $parsedConfig;
    }

    private function getSelectedValue($config, $defaultConfigValue)
    {
        /** @var \Pimcore\Model\Document\Tag\* $el */
        $el = $this->tagRenderer->getTag($this->info->getDocument(), $config['type'], $config['name']);

        //force default (only if it returns false. checkboxes may return an empty string and are impossible to track into default mode
        if (!empty($defaultConfigValue) && ($el->isEmpty() === TRUE)) {
            $el->setDataFromResource($defaultConfigValue);
        }

        $value = NULL;

        switch($config['type']) {

            case 'checkbox' :
                $value = $el->isChecked();
                break;
            default:
                $value = $el->getData();
        }

        $config['selected_value'] = !empty($value) ? $value : $defaultConfigValue;

        return $config;

    }

    private function parseInternalTypes($elConf)
    {
        if($elConf['type'] === 'additionalClasses') {
            $elConf['type'] = 'input';
            $elConf['title'] = $this->translator->trans('Additional', [], 'admin');
            $elConf['name'] = $this->documentEditableId . 'AdditionalClasses';
        }

        return $elConf;
    }

    private function parseConfigElements()
    {
        $parsedConfig = [];

        foreach ($this->configElements as $configElementName => $c) {

            $tagConfig = $c['config'];

            $parsedTagConfig = $this->getTagConfig($c['type'], $tagConfig);
            $parsedAdditionalConfig = $this->getAdditionalConfig($configElementName, $c);

            $parsedConfig[] = ['tag_config' => $parsedTagConfig, 'additional_config' => $parsedAdditionalConfig];
        }

        //condition needs to applied after all elements has been initialized!
        return self::checkCondition($parsedConfig);
    }

    private function checkCondition($configElements)
    {
        $filteredData = [];

        if (empty($configElements)) {
            return $configElements;
        }

        foreach ($configElements as $configElementName => $el) {

            //no conditions? add it!
            if (empty($el['additional_config']['conditions'])) {
                $filteredData[] = $el;
                continue;
            }

            $orConditions = $el['additional_config']['conditions'];

            $orGroup = [];
            $orState = FALSE;

            foreach ($orConditions as $andConditions) {
                $andGroup = [];
                $andState = TRUE;

                foreach ($andConditions as $andConditionKey => $andConditionValue) {
                    $andGroup[] = self::getElementState($andConditionKey, $configElements) == $andConditionValue;
                }

                if (in_array(FALSE, $andGroup, TRUE)) {
                    $andState = FALSE;
                }

                $orGroup[] = $andState;
            }

            if (in_array(TRUE, $orGroup, TRUE)) {
                $orState = TRUE;
            }

            if ($orState === TRUE) {
                $filteredData[] = $el;
            } else {
                //we need to reset value, if possible!
                $filteredData[] = self::resetElement($el);
            }
        }

        return $filteredData;
    }

    /**
     * @param string $name
     * @param        $elements
     *
     * @return null
     */
    private function getElementState($name = '', $elements)
    {
        if (empty($elements)) {
            return NULL;
        }

        foreach ($elements as $el) {
            if ($el['additional_config']['name'] === $name) {
                return $el['additional_config']['selected_value'];
            }
        }

        return NULL;
    }

    /**
     * @param $el
     *
     * @return mixed
     */
    private function resetElement($el)
    {
        $value = !empty($el['tag_config']['default']) ? $el['tag_config']['default'] : NULL;
        $this->tagRenderer->getTag($this->info->getDocument(), $el['additional_config']['type'], $el['additional_config']['name'])->setDataFromResource($value);
        $el['additional_config']['selected_value'] = $value;
        $el['additional_config']['editmode_hidden'] = TRUE;

        return $el;
    }
}