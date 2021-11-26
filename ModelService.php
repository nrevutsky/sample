<?php

namespace App\Services\Model;


use App\Models\Language\Language;
use App\Models\Model\Model;
use App\Models\Model\ModelTranslation;

class ModelService
{
    private $language_id = 1;

    public function get($id, $language_id)
    {
        $model = Model::select(['models.id', 'name', 'parent_id'])->join('model_translations', function ($join) use ($language_id) {
            $join->on('models.id', '=', 'model_translations.model_id')->where('language_id', $language_id);})->where('models.id', $id)->with(['translations'])->first();
        return $model;
    }

    public function getChildren($id, $language_id)
    {
        $model = Model::select(['models.id', 'name'])->join('model_translations', function ($join) use ($language_id) {
            $join->on('models.id', '=', 'model_translations.model_id')->where('language_id', $language_id);})->where('parent_id', $id)->with(['translations'])->orderBy('name')->get();
        return $model;
    }

    public function create($data)
    {
        $model = new Model();
        $model->parent_id = $data['parent_id'];
        $model->save();
        foreach ($data['languages'] as $language_id => $translation) {
            if ($translation) {
                $model_translation = new ModelTranslation();
                $model_translation->model_id = $model->id;
                $model_translation->language_id = $language_id;
                $model_translation->name = $translation;
                $model_translation->save();
            }
        }
        return true;
    }

    public function update($id, $data)
    {
        $model = Model::where('id', $id)->first();
        if (!$model) {
            return false;
        }

        $model->parent_id = $data['parent_id'];
        $model->save();

        ModelTranslation::where('model_id', $id)->delete();

        foreach ($data['languages'] as $language_id => $translation) {
            if ($translation) {
                $model_translation = new ModelTranslation();
                $model_translation->model_id = $model->id;
                $model_translation->language_id = $language_id;
                $model_translation->name = $translation;
                $model_translation->save();
            }
        }
        return true;
    }

    public function delete($id, $remove_child)
    {
        if ($remove_child == 'move') {
            Model::where('parent_id', $id)->update(['parent_id' => 0]);
        } else {
            $this->deleteChildren($id);
        }
        return Model::where('id', $id)->delete();
    }

    public function export($id)
    {
        if ($id == 0) {
            $name = 'root';
        } else {
            $model = ModelTranslation::where('model_id', $id)->where('language_id', $this->language_id)->first();
            if (!$model) {
                $name = $id;
            } else {
                $name = $model->name;
            }
        }
        $name = 'data/model-' . $name . '.csv';

        $this->file = fopen(public_path($name), 'w+');
        $languages = Language::all();
        $header_array = ['id','parent_id'];
        $this->languages_array = [];
        foreach ($languages as $i => $language) {
            $header_array[] = $language->slug;
            $this->languages_array[$language->id] = $i + 2;
        }
        fputcsv($this->file, $header_array);
        $this->content = [];
        $this->getChildrenAll($id);
        fclose($this->file);
        return $name;
    }

    public function import()
    {
        if (request()->file()) {
            $file_name = time() . '.csv';
            $file_path = request()->file('file')->move(storage_path('database'), $file_name);
            $file = fopen($file_path, 'r');
            $header = fgetcsv($file);
            $header_languages = [];
            for ($i = 2; $i < count($header); $i++) {
                $language = Language::where('slug', $header[$i])->first();
                if ($language) {
                    $header_languages[$i] = $language->id;
                }
            }

            while ($line = fgetcsv($file)) {
                $id = $line[0];
                $parent_id = $line[1];
                if ($parent_id == '-') {
                    $this->delete($id, 'delete');
                    continue;
                }

                if ($id == 0) {
                    $model = new Model();
                    $model->parent_id = $parent_id;
                    $model->save();
                    foreach ($header_languages as $key => $language) {
                        $name = trim($line[$key]);
                        if ($name!='' && $name!='-') {
                            $translation = new ModelTranslation();
                            $translation->name = $name;
                            $translation->language_id = $language;
                            $translation->model_id = $model->id;
                            $translation->save();
                        }
                    }
                } else {
                    $model = Model::where('id', $id)->first();
                    if ($model) {
                        $model->parent_id = $parent_id;
                        $model->save();
                        foreach ($header_languages as $key => $language) {
                            $name = trim($line[$key]);
                            if ($name!='' && $name!='-') {
                                $translation = ModelTranslation::where('model_id', $id)->where('language_id', $language)->first();
                                if (!$translation) {
                                    $translation = new ModelTranslation();
                                }
                                $translation->name = $name;
                                $translation->language_id = $language;
                                $translation->model_id = $id;
                                $translation->save();
                            } elseif ($name=='-') {
                                ModelTranslation::where('model_id', $id)->where('language_id', $language)->delete();
                            }
                        }
                    }
                }
            }
        }
    }

    private function getChildrenAll($id)
    {
        $models = Model::where('parent_id', $id)->with(['translations'])->get();
        foreach ($models as $model) {
            $tmp_content = [$model->id, $id];
            foreach ($this->languages_array as $lang) {
                $tmp_content[$lang] = '';
            }
            foreach ($model->translations as $translation) {
                if (isset($this->languages_array[$translation->language_id])) {
                    $idx = $this->languages_array[$translation->language_id];
                    $tmp_content[$idx] = $translation->name;
                }
            }
            fputcsv($this->file, $tmp_content);

            $this->getChildrenAll($model->id);

        }
    }

    private function deleteChildren ($id)
    {
        $models = Model::where('parent_id', $id)->get();
        foreach ($models as $model) {
            $this->deleteChildren($model->id);
        }
        Model::where('id', $id)->delete();
    }
}
