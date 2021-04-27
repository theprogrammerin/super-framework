<?php

namespace App\Models;


class KeyValueSuperModel extends SuperModel {

  const PARENT_MODEL = 'App\Models\SuperModel';

  public function parentModel() {
    return $this->belongsTo(
      get_called_class()::PARENT_MODEL,
      get_called_class()::PARENT_FOREIGN_KEY
    );
  }

}
