<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Helpers\StringHelper;

use App\Exceptions\ValidationFailedException;
use App\Exceptions\NothingToSaveException;

class SuperModel extends Model {

  // Schema Description
  protected $guarded = ['updated_at','created_at'];

  const WRITE_ATTRS = [];
  const KEY_VALUE_ATTRS = [];
  const KEY_VALUE_ATTRS_TYPE = [];
  const MANY_TO_MANY_RELATION_ATTRS = [];

  // KeyValue Stroage Helpers
  const KEY_VALUE_MODEL = null;
  const KEY_VALUE_FOREIGN_KEY = null;

  public function hasKeyValueAttributes() {
    return get_called_class()::KEY_VALUE_MODEL != null;
  }

  public function keyValueAttributes() {
    if($this->hasKeyValueAttributes()) {
      return $this->hasMany(
        get_called_class()::KEY_VALUE_MODEL,
        get_called_class()::KEY_VALUE_FOREIGN_KEY
      );
    }
    return null;
  }

  public function getKeyValueAttributes() {
    $attr_data = [];

    if($this->hasKeyValueAttributes()) {
      foreach($this->keyValueAttributes()->get() as $attr) {
        $key_value_type = $this->getKeyValueType($attr->key);
        if($key_value_type == '[]') {
          if(!isset($attr_data[$attr->key])) {
            $attr_data[$attr->key] = [];
          }
          array_push($attr_data[$attr->key], $attr->value);
        } else {
          $attr_data[$attr->key] = $attr->value;
        }

      }
    }
    return $attr_data;
  }

  // Data Write

  private function getKeyValueType($key) {
    $ATTR_TYPES = get_called_class()::KEY_VALUE_ATTRS_TYPE;
    $type = array_key_exists($key, $ATTR_TYPES) ? $ATTR_TYPES[$key] : null;
    return $type;
  }

  private function saveKeyValueAttr($key, $values, $old_keys) {

    $key_value_type = $this->getKeyValueType($key);
    if($key_value_type == '[]') {
      $this->keyValueAttributes()->where('key', $key)->delete();
      foreach($values as $value) {
        $this->keyValueAttributes()->create(['key'   => $key, 'value' => $value]);
      }

    } else {
      if(in_array($key, $old_keys)) {
        $this->keyValueAttributes()->where('key', $key)->update(['value'=>$values]);
      } else {
        $this->keyValueAttributes()->create(['key' => $key, 'value' => $values]);
      }
    }
  }

  public function smartSave($attrs) {
    $errors = [];
    foreach($attrs as $attr => $value) {
      $errors = array_merge($errors, $this->validateAttr($attr, $value));
    }
    if(!empty($errors)) {
      throw new ValidationFailedException("Validation Failed", $errors);
    }

    $allowed_write_attrs          = array_intersect_key($attrs, array_flip(get_called_class()::WRITE_ATTRS));

    $many_to_many_relation_attrs  = array_intersect_key($attrs, array_flip(get_called_class()::MANY_TO_MANY_RELATION_ATTRS));

    $new_key_value_attrs          = array_intersect_key($attrs, array_flip(get_called_class()::KEY_VALUE_ATTRS));


    if(
      empty($allowed_write_attrs) &&
      empty($new_key_value_attrs) &&
      empty($many_to_many_relation_attrs)) {
      throw new NothingToSaveException("There is nothing to save.");
    }

    foreach ($allowed_write_attrs as $attr => $new_value) {
      $this->setAttribute($attr, $new_value);
    }
    $this->save();

    foreach($many_to_many_relation_attrs as $attr => $ids) {
      $_saveMethod = camel_case("save_".$attr);
      $this->{$_saveMethod}($ids);
    }

    // Saving Key Values
    if($this->hasKeyValueAttributes()) {
      $old_keys = array_keys($this->getKeyValueAttributes());
      foreach($new_key_value_attrs as $key => $new_value) {
        $this->saveKeyValueAttr($key, $new_value, $old_keys);
      }
    }
  }

  const VALIDATIONS = [];

  public function validateAttr($attr, $value) {
    $errors = [];
    $validation_rules = get_called_class()::VALIDATIONS;

    if(array_key_exists($attr, $validation_rules)) {
      $validation_rule_set = explode("|", $validation_rules[$attr]);

      foreach($validation_rule_set as $validation_rule) {
        if($validation_rule == "*") {
          if(empty($value)) {
            array_push($errors, "$attr is required");
          }
        } else if(ends_with($validation_rule, '()')) {
          array_push($errors, $this->{ str_replace('()','',$validation_rule) }($value));
        } else if(!preg_match($validation_rule, $value)) {
          array_push($errors, "$attr is invalid");
        }
      }
    }
    $errors = array_filter($errors);
    return $errors;
  }

  // JSON Lists
  const LISTS = [
    "BASIC" => ['id', 'created_at', 'updated_at', 'name']
  ];

  const ALLOWED_INCLUDES = [];

  public function getCustomAttributes() {
    return [];
  }


  /** /**
   * Generate a JSON representation of an Eloquent Model
   * @param type|string $type - THe attribute set needed in the json dump
   * @param type|array $includeRealtions - The relations to include, example. If you have a one to many relation, it will return an array, but you need to tell that by using 'users[]'
   * @return type|Array
   */
  public function asJSON($list_type = 'DEFAULT', $includes = []) {

    $default_attrs    = $this->getAttributes();
    $key_value_attrs  = $this->getKeyValueAttributes();
    $custom_attrs     = $this->getCustomAttributes();

    $data = array_merge($default_attrs, $key_value_attrs, $custom_attrs);

    if(is_array($list_type)) {
      $keys = $list_type;
    } else if (array_key_exists($list_type, get_called_class()::LISTS)) {
      $keys = get_called_class()::LISTS[$list_type];
    } else {
      $keys = array_keys($data);
    }

    $data = array_filter($data, function($key) use($keys){
      return in_array($key, $keys);
    }, ARRAY_FILTER_USE_KEY);

    $includeRealtions = array_intersect(get_called_class()::ALLOWED_INCLUDES, array_keys($includes));
    if(!empty($includeRealtions)) {
      foreach($includeRealtions as $relation_key) {
        $include_list_type = 'DEFAULT';
        $include_filters = [];
        $sub_include = [];
        if(is_array($includes[$relation_key])) {
          if(array_key_exists('ATTR_SET', $includes[$relation_key])){
            $include_list_type = $includes[$relation_key]['ATTR_SET'];
          }
          if(array_key_exists('FILTERS', $includes[$relation_key])){
            $include_filters = $includes[$relation_key]['FILTERS'];
          }
          if(array_key_exists('INCLUDE', $includes[$relation_key])) {
            $sub_include = $includes[$relation_key]['INCLUDE'];
          }
        }
        $relation_data = [];
        if(ends_with($relation_key, '[]')) {
          $relation_key = str_replace('[]', '', $relation_key);
          $relation = $this->{$relation_key};
          foreach( $include_filters as $filter_method => $filter_params ) {
            $relation = call_user_func_array(array($relation, $filter_method), $filter_params);
          }
          if($relation) {
            foreach($relation as $row) {
              array_push($relation_data, $row->asJSON($include_list_type, $sub_include));
            }
          }

        }else {
          $relation = $this->{$relation_key};
          if($relation) {
            $relation_data = $relation->asJSON($include_list_type, $sub_include);
          }
        }
        $data[$relation_key] = $relation_data;
      }
    }

    return $data;
  }
}
