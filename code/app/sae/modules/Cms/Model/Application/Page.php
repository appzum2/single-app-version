<?php

class Cms_Model_Application_Page extends Core_Model_Default
{
    protected $_is_cacheable = true;
    protected $_blocks;
    protected $_metadata;
    protected $_action_view = "findall";

    public function __construct($params = array()) {
        parent::__construct($params);
        $this->_db_table = 'Cms_Model_Db_Table_Application_Page';
        return $this;
    }

    /**
     * CMS is special and used in many features
     *
     * @return array
     */
    public function getInappStates($value_id) {

        $option_value_model = new Application_Model_Option_Value();
        $option_value = $option_value_model->find($value_id);

        $option_model = new Application_Model_Option();
        $option = $option_model->find($option_value->getOptionId());

        # Special case 1. for Places
        if($option->getCode() == "places") {
            $place_model = new Places_Model_Place();
            return $place_model->getInappStates($value_id);
        }

        $in_app_states = array(
            array(
                "state" => "cms-view",
                "offline" => true,
                "params" => array(
                    "value_id" => $value_id,
                ),
            ),
        );

        return $in_app_states;
    }


    public static function findAllOrderedByRank($value_id) {
        $table = new Cms_Model_Db_Table_Application_Page();
        $select = $table->select(Zend_Db_Table::SELECT_WITH_FROM_PART);
        $select->setIntegrityCheck(false);
        $select->join('cms_application_page_block', 'cms_application_page_block.page_id = cms_application_page.page_id')
            ->join('cms_application_page_block_address', 'cms_application_page_block_address.value_id = cms_application_page_block.value_id')
            ->where("cms_application_page.value_id = ?", $value_id)
            ->order("cms_application_page_block_address.rank asc");
        return $table->fetchAll($select);
    }

    public static function findAllByPageId($value_id, $ids) {
        $table = new Cms_Model_Db_Table_Application_Page();
        $select = $table->select(Zend_Db_Table::SELECT_WITH_FROM_PART);
        $select->where("cms_application_page.value_id = ?", $value_id)
            ->where("cms_application_page.page_id IN (?)", $ids);
        return $table->fetchAll($select);
    }

    /**
     * Executed when the feature is created
     *
     * @param $option_value
     */
    public function prepareFeature($option_value) {
        self::setPlaceOrder($option_value->getValueId(), 'true');
    }

    /**
     * Handles the saving of the places_order metadatum
     *
     * @param $value_id
     * @param $order
     */
    public static function setPlaceOrder($value_id, $order) {
        // Delete old metadata value
        Application_Model_Option_Value_Metadata::deleteByCode($value_id, 'places_order');
        // Replace it with the current one
        $metadatum = new Application_Model_Option_Value_Metadata();
        $metadatum->setPayload($order ? 'true' : 'false');
        $metadatum->setCode('places_order');
        $metadatum->setValueId($value_id);
        $metadatum->setType('boolean');
        $metadatum->save();
    }

    public function findByUrl($url) {
        $this->find($url, 'url');
        return $this;
    }

    /**
     * @return Cms_Model_Application_Block[]
     */
    public function getBlocks() {

        if(is_null($this->_blocks) AND $this->getId()) {
            $block = new Cms_Model_Application_Block();
            $this->_blocks = $block->findByPage($this->getId());
        }

        return $this->_blocks;
    }

    public function getPictureUrl() {
        $path = Application_Model_Application::getImagePath().$this->getPicture();
        $base_path = Application_Model_Application::getBaseImagePath().$this->getPicture();
        return is_file($base_path) ? $path : null;
    }

    /**
     * @param $option_value
     * @return array
     */
    public function getFeaturePaths($option_value) {
        if(!$this->isCacheable()) {
            return array();
        }

        if($option_value->getCode() == "custom_page") {
            $paths = array();

            $paths[] = $option_value->getPath(
                "find",
                array(
                    'page_id' => $this->getId(),
                    'value_id' => $option_value->getId()
                ),
                false
            );

            $paths[] = $option_value->getPath(
                "findall",
                array(
                    'page_id' => $this->getId(),
                    'value_id' => $option_value->getId()
                ),
                false
            );

            $paths[] = $option_value->getPath(
                "findall",
                array(
                    'value_id' => $option_value->getId()
                ),
                false
            );

            foreach($this->getBlocks() as $block) {
                $paths[] = $option_value->getPath(
                    "findblock",
                    array(
                        'block_id' => $block->getId(),
                        'page_id' => $this->getId(),
                        'value_id' => $option_value->getId()
                    ),
                    false
                );
                $paths[] = $option_value->getPath(
                    "findblock",
                    array(
                        'block_id' => $block->getId(),
                        'value_id' => $option_value->getId()
                    ),
                    false
                );
            }
        } else {
            // Places paths
            $places = new Places_Model_Place();
            $paths =  $places->getFeaturePaths($option_value);
        }

        return $paths;
    }

    /**
     * @param $option_value
     * @return array|void
     */
    public function getAssetsPaths($option_value) {
        if(!$this->isCacheable()) {
            return array();
        }

        if($option_value->getCode() == "custom_page") {
            $paths = array();

            $val = $this->getPictureUr();
            if(!empty($val)) {
                $paths[] = $val;
            }


            foreach($this->getBlocks() as $block) {
                $data = $block->getJSONData();
                $keys = array("image_url", "cover_url", "file_url");

                foreach ($keys as $key) {
                    $val = $data[$key];
                    if(!empty($val)) {
                        $paths[] = $val;
                    }
                }

                if(is_array($data["gallery"])) {
                    foreach ($data["gallery"] as $img) {
                        $paths[] = $img["src"];
                    }
                }

                if($block->getType() == "video" && $data["video_type_id"] == "link") {
                    $paths[] = $data["url"];
                }
            }

        } else {
            // Places paths
            $places = new Places_Model_Place();
            $paths = $places->getAssetsPaths($option_value);
        }

        return $paths;
    }

    public function save() {
        parent::save();
        $blocks = $this->getData('block') ? $this->getData('block') : array();
        $this->getTable()->saveBlock($this->getId(), $blocks);
    }

    /**
     * No needs for saveBlocks anymore
     */
    public function save_v2() {
        parent::save();
    }


    /**
     * Method create/edit
     *
     * @param $option_value
     * @param $datas
     */
    public function edit_v2($option_value, $datas) {
        try {

            if(!$option_value) {
                throw new Siberian_Exception("#578-01".__("An error occurred while saving your page."));
            }

            $value_id = $option_value->getId();

            # Create a new CMS Page
            $page = new Cms_Model_Application_Page();
            if(!isset($datas["page_id"])) {
                $page
                    ->setValueId($value_id)
                    ->save_v2() # save_v2 is a simple save, without all the old saveBlocks
                ;
            } else {
                $page->find($datas["page_id"]);

                if($page->getId() && ($page->getValueId() != $value_id)) {
                    throw new Siberian_Exception("#578-02".__("An error occurred while saving your page."));
                }
            }

            # Page title
            $page
                ->setTitle($datas["title"])
                ->save_v2();

            # Clear all page_blocks
            $cms_page_block = new Cms_Model_Application_Page_Block();
            $cms_page_blocks = $cms_page_block->findAll(array("page_id = ?" => $page->getId()));
            foreach($cms_page_blocks as $cms_page_block) {
                $cms_page_block->delete();
            }

            //$blocks = array();
            $block_position = 0;
            foreach ($datas["block"] as $uniqid => $block) {
                $block_type = key($block);
                $values = $block[$block_type];

                switch($block_type) {
                    case "text":
                        $model = new Cms_Model_Application_Page_Block_Text();
                        break;
                    case "image":
                        $model = new Cms_Model_Application_Page_Block_Image();
                        break;
                    case "video":
                        $model = new Cms_Model_Application_Page_Block_Video();
                        break;
                    case "address":
                        $model = new Cms_Model_Application_Page_Block_Address();
                        break;
                    case "button":
                        $model = new Cms_Model_Application_Page_Block_Button();
                        break;
                    case "file":
                        $model = new Cms_Model_Application_Page_Block_File();
                        break;
                    case "slider":
                        $model = new Cms_Model_Application_Page_Block_Slider();
                        break;
                    case "cover":
                        $model = new Cms_Model_Application_Page_Block_Cover();
                        break;
                    default:
                        throw new Siberian_Exception(__("This block type doesn't exists."));
                }

                $model
                    ->setOptionValue($option_value)
                    ->populate($values)
                    ->createBlock($block_type, $page, $block_position)
                    ->save_v2()
                ;
                $block_position++;
            }

            return $page;

        } catch(Exception $e) {
            die("Exception: ".$e->getMessage());
        }
    }

    /**
     * @deprecated
     *
     * @param $option_value
     * @param $design
     * @param $category
     */
    public function createDummyContents($option_value, $design, $category) {

        $option = new Application_Model_Option();
        $option->find($option_value->getOptionId());

        $dummy_content_xml = $this->_getDummyXml($design, $category);

        if($option->getCode() == 'places' && $dummy_content_xml->places) {

            foreach ($dummy_content_xml->places->children() as $content) {

                $this->unsData();

                $blocks = array();
                $i = 1;
                foreach ($content->block as $block_content) {

                    $block = new Cms_Model_Application_Block();
                    $block->find((string) $block_content->type, "type");

                    $data = (array) $block_content;
                    if($block_content->image_url) {
                        $data['image_url'] = (array) $block_content->image_url;
                        $data['image_fullsize_url'] = (array) $block_content->image_fullsize_url;
                    }
                    $data["block_id"] = $block->getId();

                    $blocks[$i++] = $data;
                }

                $this->addData((array) $content->content)
                    ->setBlock($blocks)
                    ->setValueId($option_value->getId())
                    ->save()
                ;
            }

        } else {

            $blocks = array();
            $i = 1;
            foreach ($dummy_content_xml->blocks->children() as $content) {

                $block = new Cms_Model_Application_Block();
                $block->find((string) $content->type, "type");

                $data = (array) $content;
                if($content->image_url) {
                    $data['image_url'] = (array) $content->image_url;
                    $data['image_fullsize_url'] = (array) $content->image_fullsize_url;
                }
                $data["block_id"] = $block->getId();

                $blocks[$i++] = $data;
            }

            $this->setValueId($option_value->getId())
                ->setBlock($blocks)
                ->save();

        }
    }

    /**
     * @param $option
     */
    public function copyTo($option) {
        $blocks = array();

        foreach($this->getBlocks() as $block) {
            switch($block->getType()) {
                case 'image':
                case 'cover':
                case 'slider':
                    $library = new Cms_Model_Application_Page_Block_Image_Library();
                    $images = $library->findAll(array('library_id' => $block->getLibraryId()), 'image_id ASC', null);
                    $block->unsId(null)
                        ->unsLibraryId(null)
                        ->unsImageId()
                        ->unsCoverId()
                        ->unsSliderId();
                    $new_block = $block->getData();
                    $new_block['image_url'] = array();
                    $new_block['image_fullsize_url'] = array();
                    $new_block['library_id'] = null;
                    $new_block['type'] = $block->getType();

                    foreach($images as $image) {
                        $new_block['image_url'][] = $image->getData('image_url');
                        $new_block['image_fullsize_url'][] = $image->getData('image_url');
                    }
                    $blocks[] = $new_block;
                    break;

                case 'video':
                    $object = $block->getObject();
                    $object->setId(null);
                    $block->unsId(null)->unsVideoId();
                    $blocks[] = $block->getData() + $object->getData();
                    break;

                case 'address' :
                    $object = $block->getObject();
                    $object->setId(null);
                    $block->unsId(null)->unsAddressId();
                    $blocks[] = $block->getData() + $object->getData();
                    break;

                case 'text' :
                    $content = $block->getContent();
                    $content = preg_replace('/<a(.*)data-state=(.*)>(.*)<\/a>/mi', '', $content);
                    $block->setData("content", $content);

                case 'button' :
                    $block->unsButtonId();

                case 'file' :
                    $block->unsFileId();

                default:
                    $block->unsId(null)->unsTextId();
                    $blocks[] = $block->getData();
                    break;
            }

        }

        $this->setData('block', $blocks);
        $this->setId(null)
            ->setValueId($option->getId())
            ->save()
        ;

    }

    /**
     * Returns all the metadata associated with the current page
     *
     * @return collection           A collection of Cms_Model_Db_Table_Application_Page_Metadata
     */
    public function getMetadatas()
    {
        $matadata = new Cms_Model_Application_Page_Metadata();
        $results = $matadata->findAll(array('page_id' => $this->getPageId()));
        $this->_metadata = array();
        foreach ($results as $result) {
            array_push($this->_metadata, $result);
        }
        return $this->_metadata;
    }

    /**
     * Returns the metadatum which has the code specified.
     *
     * @param string                e.g. 'show_titles' or 'show_image'
     * @return collection           A collection of Cms_Model_Db_Table_Application_Page_Metadata
     */
    public function getMetadata($code)
    {
        $metadata = new Cms_Model_Application_Page_Metadata();
        $metadata->find(array('page_id' => $this->getPageId(), 'code' => $code));
        return $metadata;
    }

    /**
     * Sets the page metadata to the data provided.
     * Assumes all the keys absent to be removed.
     *
     * @param array                 An array of key, value tuples
     * @return $this
     */
    public function setMetadata($data)
    {
        foreach ($this->getMetadatas() as $metadatum) {
            // If there already are metadata with the given name update them
            if (array_key_exists($metadatum->getCode(), $data)) {
                $metadatum->setPayload($data[$metadatum->getCode()]);
                unset($data[$metadatum->getCode()]);
            } else {
                $metadatum->setPayload(null);
            }
        }
        // Otherwize create a new metadata and add it to the current metadata
        foreach ($data as $code => $payload) {
            array_push($this->_metadata, $this->_createMetadata($code, $payload));
        }
        return $this;
    }

    /**
     * Gets the string value of the metadatum specified by the $code
     *
     * @param string                 Metadatum name
     * @return string
     */
    public function getMetadataValue($code)
    {
        $meta = $this->getMetadata($code);
        if ($meta) {
            return $meta->getPayload();
        } else {
            return null;
        }
    }

    /**
     * Save the metadata defined on the current page
     *
     * @return $this
     */
    public function saveMetadata(){
        foreach ($this->_metadata as $metadatum) {
            $metadatum->save();
        }
        return $this;
    }

    /**
     * Creates an instance of Cms_Model_Application_Page_Metadata and configures it with the current page
     *
     * @return $metadata     An instance of Cms_Model_Application_Page_Metadata
     */
    protected function _createMetadata($code, $payload)
    {
        $meta = new Cms_Model_Application_Page_Metadata();
        $meta->setPageId($this->getPageId());
        $meta->setCode($code);
        $meta->setPayload($payload);
        return $meta;
    }
}