<?php

namespace mdm\behaviors\ar;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * Description of RelatedBehavior
 *
 * @property \yii\db\ActiveRecord $owner Description
 * @author Misbahul D Munir (mdmunir) <misbahuldmunir@gmail.com>
 */
class RelatedBehavior extends \yii\base\Behavior
{
    public $extraData;
    public $beforeRValidate;
    public $beforeRSave;
    public $afterRSave;
    public $clearError = true;
    private $_relatedErrors = [];

    /**
     *
     * @param  string  $relations
     * @param  array   $data
     * @param  boolean   $save
     * @return boolean Description
     */
    public function saveRelated($relationName, $data, $saved = true, $scope = null)
    {
        return $this->doSaveRelated($relationName, $data, $saved, $scope);
    }

    /**
     *
     * @param  string  $relationName
     * @param  array   $data
     * @param  boolean $save
     * @return boolean
     */
    protected function doSaveRelated($relationName, $data, $save, $scope)
    {
        $model = $this->owner;
        $relation = $model->getRelation($relationName);

        // link of relation
        $links = [];
        foreach ($relation->link as $from => $to) {
            $links[$from] = $model[$to];
        }

        /* @var $class \yii\db\ActiveRecord */
        $class = $relation->modelClass;
        $multiple = $relation->multiple;
        if ($multiple) {
            $children = $relation->all();
        } else {
            $children = $relation->one();
        }
        $pks = $class::primaryKey();

        if ($scope === null) {
            $postDetails = ArrayHelper::getValue($data, (new $class)->formName(), []);
        } elseif ($scope === false) {
            $postDetails = $data;
        } else {
            $postDetails = ArrayHelper::getValue($data, $scope, []);
        }

        if ($this->clearError) {
            $this->_relatedErrors[$relationName] = [];
        }
        /* @var $detail \yii\db\ActiveRecord */
        $error = false;
        if ($multiple) {
            $population = [];
            foreach ($postDetails as $index => $dataDetail) {
                if (isset($this->extraData)) {
                    $dataDetail = array_merge($this->extraData, $dataDetail);
                }
                $dataDetail = array_merge($dataDetail, $links);

                // set primary key of detail
                $detailPks = [];
                if (count($pks) === 1) {
                    $detailPks = isset($dataDetail[$pks[0]]) ? $dataDetail[$pks[0]] : null;
                } else {
                    foreach ($pks as $pkName) {
                        $detailPks[$pkName] = isset($dataDetail[$pkName]) ? $dataDetail[$pkName] : null;
                    }
                }

                $detail = null;
                // get from current relation
                // if has child with same primary key, use this
                if (empty($relation->indexBy)) {
                    foreach ($children as $i => $child) {
                        if ($child->getPrimaryKey() === $detailPks) {
                            $detail = $child;
                            unset($children[$i]);
                            break;
                        }
                    }
                } elseif (isset($children[$index])) {
                    $detail = $children[$index];
                    unset($children[$index]);
                }
                if ($detail === null) {
                    $detail = new $class;
                }

                $detail->load($dataDetail, '');

                if (isset($this->beforeRValidate)) {
                    call_user_func($this->beforeRValidate, $detail, $index);
                }
                if (!$detail->validate()) {
                    $this->_relatedErrors[$relationName][$index] = $detail->getFirstErrors();
                    $error = true;
                }
                $population[$index] = $detail;
            }
        } else {
            $population = $children === null ? new $class : $children;
            $dataDetail = $postDetails;
            if (isset($this->extraData)) {
                $dataDetail = array_merge($this->extraData, $dataDetail);
            }
            $dataDetail = array_merge($dataDetail, $links);
            $population->load($dataDetail, '');
            if (isset($this->beforeRValidate)) {
                call_user_func($this->beforeRValidate, $population, null);
            }
            if (!$population->validate()) {
                $this->_relatedErrors[$relationName] = $population->getFirstErrors();
                $error = true;
            }
        }

        if (!$error && $save) {
            if ($multiple) {
                // delete current children before inserting new
                $linkFilter = [];
                $columns = array_flip($pks);
                foreach ($relation->link as $from => $to) {
                    $linkFilter[$from] = $model->$to;
                    // reduce primary key that linked to parent
                    if (isset($columns[$from])) {
                        unset($columns[$from]);
                    }
                }
                $values = [];
                if (!empty($columns)) {
                    $columns = array_keys($columns);
                    foreach ($children as $child) {
                        $value = [];
                        foreach ($columns as $column) {
                            $value[$column] = $child[$column];
                        }
                        $values[] = $value;
                    }
                    if (!empty($values)) {
                        $class::deleteAll(['and', $linkFilter, ['in', $columns, $values]]);
                    }
                } else {
                    $class::deleteAll($linkFilter);
                }
                foreach ($population as $index => $detail) {
                    if (!isset($this->beforeRSave) || call_user_func($this->beforeRSave, $detail, $index) !== false) {
                        $detail->save(false);
                        if (isset($this->afterRSave)) {
                            call_user_func($this->afterRSave, $detail, $index);
                        }
                    }
                }
            } else {
                if (!isset($this->beforeRSave) || call_user_func($this->beforeRSave, $population, null) !== false) {
                    $population->save(false);
                    if (isset($this->beforeRSave)) {
                        call_user_func($this->beforeRSave, $population, null);
                    }
                }
            }
        }

        $model->populateRelation($relationName, $population);

        return !$error && $save;
    }
    
    /**
     * 
     * @param string $relationName
     * @return boolean
     */
    public function hasRelatedError($relationName=null)
    {
        if($relationName === null){
            return !empty($this->_relatedErrors);
        }  else {
            return !empty($this->_relatedErrors[$relationName]);
        }
    }
}