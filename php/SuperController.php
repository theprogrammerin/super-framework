<?php

namespace App\Http\Controllers;

use DB;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Illuminate\Http\Request;

use App\Http\Middleware\SuperAuth;

use App\Exceptions\ValidationFailedException;
use App\Exceptions\NothingToSaveException;

class SuperController extends BaseController {

  const BASE_MODEL = null;

  const JSON_INCLUDES = [];

  const JSON_ATTR_SET = 'ALL';

  protected $request;

  public function __construct(Request $request) {
    // parent::__construct($request);
    $this->base_model = get_called_class()::BASE_MODEL;
    $request->user = SuperAuth::getUserFromRequest($request);
    $this->request = $request;
    $this->user = $request->user;
  }

  public function index(Request $request) {
    $models = $this->baseModel();
    $models = $this->commonFilter($models);
    $models = $this->indexFilter($models);
    $models = $models->orderBy('created_at','desc')->get();

    $data = [];
    foreach($models as $model) {
      array_push($data,
        $model->asJSON(
          $this->jsonAttrSet(),
          $this->jsonIncludes()
        )
      );
    }
    return $this->jsonResponse($data);
  }

  public function show(Request $request, $id) {
    $model = $this->baseModel();
    $model = $this->commonFilter($model);
    $model = $model->where('id', $id)->first();

    if(!$model) {
      return $this->jsonResponse([], "Model not found", 404);
    }
    $data = $model->asJSON($this->jsonAttrSet(), $this->jsonIncludes());
    return $this->jsonResponse($data);;
  }

  public function create(Request $request) {
    $model = $this->baseModel();
    $this->beforeCreate($model);
    return $this->smartSave($model);
  }

  public function update(Request $request, $id) {
    $model = $this->baseModel()->where('id', $id)->first();
    $this->beforeUpdate($model);
    return $this->smartSave($model);
  }

  public function delete(Request $request, $id) {
    $model = $this->baseModel()->where('id', $id)->first();
    $this->beforeDelete($model);
    return $this->jsonResponse([], ["Not Supported"], 200);;
  }

  public function smartSave($model) {
    try {
      $model->smartSave($this->request->all());
      $this->afterSave($model);
    } catch(ValidationFailedException $e) {
      return $this->jsonResponse([], $e->getData(), 400);
    } catch(NothingToSaveException $e) {
      return $this->jsonResponse([], $e->getMessage(), 400);
    }

    $data = $model->asJSON($this->jsonAttrSet(), $this->jsonIncludes());
    return $this->jsonResponse($data, [], 200);;
  }

  public function jsonResponse($data, $errors = [], $code = 200) {
    $status = empty($errors);
    return response()->json([
      "status" => $status,
      "data" => $data,
      "errors" => $errors
    ], $code);
  }


  public function commonFilter($models) {
    return $models;
  }

  public function indexFilter($models) {
    return $models;
  }

  private function baseModel() {
    return new $this->base_model;
  }

  private function jsonAttrSet() {
    $only = $this->request->query('only', "");
    $only = array_filter(explode(",", $only));
    if(!empty($only)) {
      return $only;
    } else {
      return get_called_class()::JSON_ATTR_SET;
    }
  }
  private function jsonIncludes() {
    $with_set = $this->request->query('with', "");
    $with_set = array_filter(explode(",", $with_set));
    $includes = get_called_class()::JSON_INCLUDES;
    if(!empty($with_set)) {
      $includes = array_filter($includes, function($with) use($with_set){
        return in_array($with, $with_set);
      }, ARRAY_FILTER_USE_KEY);
    }
    return $includes;
  }

  protected function beforeCreate(&$model) {}
  protected function beforeUpdate(&$model) {}
  protected function beforeDelete(&$model) {}

  protected function afterSave(&$model) {}
}
