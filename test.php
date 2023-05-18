<?php
//контроллер Laravel
namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\KosherLevel;
use App\Models\Product;
use Illuminate\Http\Request;

const perPage = 30;

class CatalogController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function category($slug) {
        $category = Category::where('slug', $slug)->firstOrFail();
        return view('catalog.category', compact('category'));
    }

    public function brand($slug) {
        $brand = Brand::where('slug', $slug)->firstOrFail();
        return view('catalog.brand', compact('brand'));
    }

    public function product($slug) {
        $product = Product::where('slug', $slug)->firstOrFail();
        return view('catalog.product', compact('product'));
    }

    public function index(Request $request)
    {
        $brand_ids = $request->brands;
        $kosher_levels = $request->kosher_levels;
        $products = Product::orderByDesc('updated_at')->paginate(perPage);
        if ($brand_ids != null && $kosher_levels == null) {
            $products = Product::whereIn('brand_id', $brand_ids)->orderByDesc('updated_at')->paginate(perPage);
        }
        else if ($kosher_levels != null && $brand_ids == null) {
            $products = Product::whereIn('kosher_level', $kosher_levels)->orderByDesc('updated_at')->paginate(perPage);
        }
        else if ($kosher_levels != null && $brand_ids != null) {
            $products = Product::whereIn('kosher_level', $kosher_levels)->whereIn('brand_id', $brand_ids)->orderByDesc('updated_at')->paginate(perPage);
        }

        $categories = Category::where('parent_id', 0)->get();
        $tree = [];
        $category_path = collect(array_reverse($tree));

        $brands_view = Brand::all()->sortBy('name');
        $levels_view = KosherLevel::all()->sortBy('name');

        return view('catalog', ['products' => $products, 'categories' => $categories,
            'parent_category' => null, 'current_category' => null,
            'category_path' => $category_path, 'brands_view' => $brands_view, 'levels_view' => $levels_view, 'title' => 'Кошерные продукты']);
    }

    public function search(Request $request){

        $search = $request->word;
        $search = iconv_substr($search, 0, 64);
        $search = preg_replace('#[^0-9a-zA-ZА-Яа-яёЁ]#u', ' ', $search);
        $search = preg_replace('#\s+#u', ' ', $search);
        $search = trim($search);
        if (empty($search)) {
            $products = null;
            return view('search', ['products' => $products, 'count' => 0]);
        }

        $brand_id = Brand::whereRaw('MATCH(name) AGAINST("' . $search . '")')->get('id');
        $category_id = Category::whereRaw('MATCH(name) AGAINST("' . $search . '")')->get('id');
        $products_brands = Product::whereIn('brand_id', $brand_id);
        $products_categories = Product::whereIn('category_id', $category_id);
        $products_barcode = Product::where('barcode', 'like', $search);

        $products = Product::whereRaw('MATCH(name) AGAINST("' . $search . '")')
            ->union($products_brands)
            ->union($products_barcode)
            ->union($products_categories);

        $count = $products->count();
        $products = $products->paginate(perPage);

        return view('search', ['products' => $products, 'count' => $count, 'title' => 'Поиск кошерных продуктов']);
    }

    public function selectCategory(Request $request, $slug)
    {
        $brand_ids = $request->brands;
        $kosher_levels = $request->kosher_levels;

        $current_id = Category::where('slug', $slug)->first()->id;
        $current_category = Category::where('slug', $slug)->first();

        $parent_id = Category::where('slug', $slug)->first()->parent_id;
        $children_ids = Category::where('parent_id', $current_id)->get('id');

        $parent_category = Category::where('slug', $slug)->first()->parent()->first();

        $first_parent = $current_id;
        $tree = [];
        do{
            $tree[] = $first_parent;
            $first_parent = Category::where('id', $first_parent)->first()->parent_id;
        } while ($first_parent != 0);

        $category_path = collect(array_reverse($tree));

        if ($parent_id == 0) {

            $products = Product::whereIn('category_id', $children_ids)->orwhere('category_id', $current_id)->paginate(perPage);
            $categories = Category::whereIn('id', $children_ids)->get();

            if ($brand_ids != null && $kosher_levels == null) {
                $products = Product::whereIn('brand_id', $brand_ids)->whereIn('category_id', $children_ids)->orderByDesc('updated_at')->paginate(perPage);
            }
            else if ($kosher_levels != null && $brand_ids == null) {
                $products = Product::whereIn('kosher_level', $kosher_levels)->whereIn('category_id', $children_ids)->orderByDesc('updated_at')->paginate(perPage);
            }
            else if ($kosher_levels != null && $brand_ids != null) {
                $products = Product::whereIn('kosher_level', $kosher_levels)->whereIn('brand_id', $brand_ids)->whereIn('category_id', $children_ids)->orderByDesc('updated_at')->paginate(perPage);
            }
            $brands_view = Brand::whereIn('id', Product::whereIn('category_id', $children_ids)->orwhere('category_id', $current_id)->orderBy('name')->get("brand_id"))->get();
            $levels_view = KosherLevel::whereIn('id', Product::whereIn('category_id', $children_ids)->orwhere('category_id', $current_id)->orderBy('name')->get("kosher_level"))->get();
        }
        else {
            $products = Product::where('category_id', $current_id)->paginate(perPage);
            $categories_ids = Category::where('parent_id', $current_id)->get('id');

            $categories = Category::whereIn('id', $categories_ids)->get();
            if ($brand_ids != null && $kosher_levels == null) {
                $products = Product::whereIn('brand_id', $brand_ids)->where('category_id', $current_id)->orderByDesc('updated_at')->paginate(perPage);
            }
            else if ($kosher_levels != null && $brand_ids == null) {
                $products = Product::whereIn('kosher_level', $kosher_levels)->where('category_id', $current_id)->orderByDesc('updated_at')->paginate(perPage);
            }
            else if ($kosher_levels != null && $brand_ids != null) {
                $products = Product::whereIn('kosher_level', $kosher_levels)->whereIn('brand_id', $brand_ids)->where('category_id', $current_id)->orderByDesc('updated_at')->paginate(perPage);
            }
            $brands_view = Brand::whereIn('id', Product::where('category_id', $current_id)->orderBy('name')->get("brand_id"))->get();
            $levels_view = KosherLevel::whereIn('id', Product::where('category_id', $current_id)->orderBy('name')->get("kosher_level"))->get();
        }

        return view('catalog', ['products' => $products, 'categories' => $categories,
            'parent_category' => $parent_category, 'current_category' => $current_category,
            'category_path' => $category_path, 'brands_view' => $brands_view, 'levels_view' => $levels_view, 'title' => 'Кошерные продукты']);
    }
}
	
	
	//запрос обратной связи с антифродом
	public function requestContact(Request $request){
		
        if(isset($_POST['h-captcha-response']) && !empty($_POST['h-captcha-response'])){
            $secret = 's';
            $verifyResponse = file_get_contents('https://hcaptcha.com/siteverify?secret='.$secret.'&response='.
                $_POST['h-captcha-response'].'&remoteip='.$_SERVER['REMOTE_ADDR']);
            $responseData = json_decode($verifyResponse);
            if($responseData->success){
                $request->validate([
                    'name' => ['required', 'string', 'min:2','max:100'],
                    'company_name' => ['required', 'string', 'min:2', 'max:1000'],
                    'phone' => ['required', 'string', 'min:10', 'max:1000'],
                    'theme' => ['required', 'string', 'min:5', 'max:1000'],
                    'description' => ['nullable', 'string', 'max:5000'],
                    'email' => ['nullable', 'string', 'min:5', 'max:250'],
                ]);

                $new = Contact::create([
                    'name' => $request->name,
                    'company_name' => $request->company_name,
                    'phone' => $request->phone,
                    'theme' => 'Заявка на сертификацию',
                    'description' => $request->message,
                    'email' => $request->email,
                ]);
                $new->save();

                $admins = User::where('access', '>', 0)->get();

                foreach ($admins as $admin){
                    Mail::to($admin->email)->send(new ContactRequest($new));
                }

                return view('contact', ['status' => 'ok']);
            }
            else{
                $new = Contact::create([
                    'name' => 'НЕПРАВИЛЬНАЯ КАПЧА',
                    'company_name' => $request->company_name,
                    'phone' => $request->phone,
                    'theme' => $request->theme,
                    'description' => $_POST['h-captcha-response'],
                    'email' => $request->email,
                ]);
                $new->save();

                return view('contact', ['status' => 'error']);
            }
        } else{
            $new = Contact::create([
                'name' => 'ПУСТАЯ КАПЧА',
                'company_name' => $request->company_name,
                'phone' => $request->phone,
                'theme' => $request->theme,
                'description' => $_POST['h-captcha-response'],
                'email' => $request->email,
            ]);
            $new->save();

            return view('contact', ['status' => 'error']);
        }
    }
	
	//пример сложного алгоритма, группировка в правильном порядке исполнения с задержками, псевдопараллельность (на одну паузу отправить все команды разных объектов с такой же паузой одновременно, а не в ряд)
    public function sendCmdGroup(){
        if ($this->rbac->check_permission('OBJECT_CONTROL')) {
            $group_id = $this->input->post('group_id');
            $schedule = $this->input->post('schedule');
            $this->load->model('object_group/object_group_model');
            $cmds = $this->object_group_model->get_commands_ag($group_id);
            $sorted_cmds = [];

            foreach ($cmds as $cmd){
                $objectId = $cmd['CabinetObjectListID'];
                $decode = json_decode($cmd['CmdValue']);
                $values = $decode->values;
                $manual = intval($decode->manual) === 1 ? 0 : null;
                $control = $cmd['CmdID'] == 1 ? "switch" : "mode";

                $query = $this->db->query('SELECT "CabinetModificationID", "ContactorCount" FROM "List_Cabinets" WHERE "ObjectListID" = ' . $objectId)->row_array();
                $cabinetModification = $query['CabinetModificationID'];
                $contactorCount = $query['ContactorCount'];
                if ($control == "switch"){
                    if (is_array($values)) {
                        if ($manual === 0) {
                            for($i = 0; $i < $contactorCount; $i++) {
                                if ($values[$i] != null) {
                                    array_push($sorted_cmds, array($objectId, $values[$i]->o, "mode", $manual, $cabinetModification));
                                }
                            }
                            array_push($sorted_cmds, array($objectId, 'PAUSE'));
                        }
                        for($i = 0; $i < $contactorCount; $i++) {
                            if ($values[$i] != null) {
                                array_push($sorted_cmds, array($objectId, intval($values[$i]->o), $control, intval($values[$i]->v), $cabinetModification));
                            }
                        }
                    } else {
                        $output = "all";
                        if ($manual === 0) {
                            array_push($sorted_cmds, array($objectId, $output, "mode", $manual, $cabinetModification));
                            array_push($sorted_cmds, array($objectId, 'PAUSE'));
                        }
                        array_push($sorted_cmds, array($objectId, $output, $control, intval($values), $cabinetModification));
                    }
                    array_push($sorted_cmds, array($objectId, 'PAUSE'));
                } else if ($control == "mode") {
                    if (is_array($values)) {
                        for($i = 0; $i < $contactorCount; $i++) {
                            if ($values[$i] != null) {
                                array_push($sorted_cmds, array($objectId, intval($values[$i]->o), $control, intval($values[$i]->v), $cabinetModification));
                            }
                        }
                    } else {
                        $output = "all";
                        array_push($sorted_cmds, array($objectId, $output, $control, intval($values), $cabinetModification));
                    }
                    array_push($sorted_cmds, array($objectId, 'PAUSE'));
                }
            }

            $ids = [];
            foreach ($sorted_cmds as $cmd){
                $ids[] = $cmd[0];
            }

            $ids = array_unique($ids);

            $cabinets = [];
            foreach($ids as $id){
                $cabinets[] = array_values(array_filter($sorted_cmds, function ($k) use ($id){
                   return $id == $k[0];
                }));
            }

            for($i = 0; true; $i++) {
                $needPause = false;
                $cabinets_count = sizeof($cabinets);
                for ($cabinet_id = 0; $cabinet_id < $cabinets_count; $cabinet_id++) {
                    if(empty($cabinets[$cabinet_id])){
                        continue;
                    }
                    $cabinet_cmds_count = sizeof($cabinets[$cabinet_id]);
                    for ($cmd_id = 0; $cmd_id < $cabinet_cmds_count; $cmd_id++) {
                        if ($cabinets[$cabinet_id][$cmd_id][1] !== 'PAUSE') {
                            $this->cabinet_control_outputs_run($cabinets[$cabinet_id][$cmd_id][0], $cabinets[$cabinet_id][$cmd_id][1], $cabinets[$cabinet_id][$cmd_id][2],
                                $cabinets[$cabinet_id][$cmd_id][3], $cabinets[$cabinet_id][$cmd_id][4]);
                            unset($cabinets[$cabinet_id][$cmd_id]);
                        } else {
                            unset($cabinets[$cabinet_id][$cmd_id]);
                            if(!empty($cabinets[$cabinet_id])) {
                                $needPause = true;
                            }
                            break;
                        }
                    }
                    $cabinets[$cabinet_id] = array_values($cabinets[$cabinet_id]);
                }
                if ($needPause) {
                   sleep(3);
                } else {
                    break;
                }
            }
            $data['group_id'] = $group_id;
            $data['schedule'] = $schedule;
            $response =  json_encode(['success' => true, 'data' => $data]);
        }
        else{
            $response =  json_encode(['success' => false, 'msg' => localize('Доступ запрещён')]);
        }

        echo $response;
    }
	
		//CodeIgnitor, новый объект
		function save_panel(){
        if ($this->rbac->check_permission('OBJECT_CONTROL')) {
            $data = $this->input->post();
            $objectID = (int)$data['ID'];
            $old = $this->db->query('SELECT "panel" FROM "List_Cabinets" WHERE "ObjectListID" = ' . $data['ID'])->row('panel');


            if ($data['Model'] == 0){
                $this->db->query('UPDATE "List_Cabinets" SET "panel" = null WHERE "ObjectListID" = ' . $data['ID']);
                echo json_encode(['success' => true]);
            }
            else {
                $panel = array(
                    "Model" => $data['Model'],
                    "TrSet" => [$data['COM'], $data['Baudrate'], $data['Bits'], $data['Parity'], $data['Stopbits']],
                    "Addr" => (int)$data['Address'],
                );
                $panel_json = json_encode($panel);


                $this->db->query('UPDATE "List_Cabinets" SET "panel" = \'' . $panel_json . '\' WHERE "ObjectListID" = ' . $data['ID']);

                echo json_encode(['success' => true]);
            }
            $logData = json_encode([
                'Panel' => $panel
            ], JSON_UNESCAPED_UNICODE);
            $this->log_user_actions_model->logActionDefault($this->session->userdata('id'), UserActionType::ACTION['SAVE_PANEL'], $objectID, $logData);
        }
        else {
            echo json_encode(['success' => false, 'msg' => localize('Доступ запрещён')]);
        }
    }
?>
	<!--
	фронт, vue.js
	-->
	<template>
	  <v-container fluid class="py-md-3">
		<v-form v-model="validForm">
		  <v-row>
			<v-col cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-select
				  :items="catalogs.Model"
				  v-model="panel.Model"
				  label="Тип панели"
				  item-text="PanelName"
				  item-value="ID"
				  :menu-props="{ offsetY: true }"
				  dense
				  outlined
				  hide-details
				  class="mb-2"
				  required
			  >
			  </v-select>
			</v-col>
			<v-col v-if="panel.Model != 0" cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-text-field
				  outlined
				  dense
				  label="Адрес"
				  required
				  hide-details
				  class="mb-2"
				  v-model="panel.Addr"
			  ></v-text-field>
			</v-col>
			<v-col v-if="panel.Model != 0" cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-select outlined dense hide-details
						:items="catalogs.COM"
						item-value="val"
						item-text="text"
						v-model.number="panel.TrSet[0]"
						label="Номер RS-485"
						class="mb-2"
			  ></v-select>
			</v-col>
			<v-col v-if="panel.Model != 0" cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-select outlined dense hide-details
						:items="catalogs.Baudrate"
						item-text="text"
						item-value="val"
						v-model.number="panel.TrSet[2]"
						label="Скорость Baud Rate"
						class="mb-2"
			  ></v-select>
			</v-col>
			<v-col v-if="panel.Model != 0" cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-select outlined dense hide-details
						:items="catalogs.Bits"
						v-model="panel.TrSet[1]"
						item-text="text"
						item-value="val"
						label="Количество бит данных"
						class="mb-2"
			  ></v-select>
			  </v-col>
			<v-col v-if="panel.Model != 0" cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-select outlined dense hide-details
						:items="catalogs.Parity"
						item-text="text"
						item-value="val"
						:rules="[x => !!x || 'Выберите четность']"
						v-model.number="panel.TrSet[3]"
						label="Четность Parity"
						class="mb-2"
			  ></v-select>
			</v-col>
			<v-col v-if="panel.Model != 0" cols="12" md="6" lg="4" xl="3" class="pt-1 pb-0">
			  <v-select outlined dense hide-details
						:items="catalogs.Stopbits"
						item-text="text"
						item-value="val"
						v-model.number="panel.TrSet[4]"
						label="Стоповый бит"
						class="mb-2"
			  ></v-select>
		  </v-col>
		  </v-row>
		</v-form>
		<div class="d-flex">
		  <v-btn @click="savePanel()" color="primary">Сохранить</v-btn>
		</div>
	  </v-container>
	</template>

	<script>
	import request from "../../../../helpers/request";

	export default {
	  name: "CabinetPanel",
	  data: () => ({
		dataset: {},
		catalogs: {
		  Model: [{ID: 0, PanelName: " ", Model: " "}],
		  Baudrate: [4800, 9600, 19200, 38400, 57600, 115200],
		  COM: [{val: 0, text: 1}, {val: 1, text: 2}],
		  Bits: [7, 8],
		  Parity: [{val: 'none', text: 'NONE'}, {val: 'even', text: 'EVEN'}, {val: 'odd', text: 'ODD'}],
		  Stopbits: [{val: 0, text: 1}, {val: 1, text: 2}]
		},
		panel: {Model: 0, TrSet: [0, 9600, 8, 'none', 0], Addr: 0},
		validForm: true,
	  }),
	  watch: {
	  },
	  methods: {
		getPanelName(id){
		  return this.catalogs.Model[id].PanelName;
		},
		savePanel: function () {
		  this.dialogAdd = false;
		  this.isLoading = true;
		  let data = null;
		  if (this.panel.Model != 0) {
			data = {
			  ID: this.$store.getters["object/getObjectID"],
			  Model: this.panel.Model,
			  COM: this.panel.TrSet[0],
			  Baudrate: this.panel.TrSet[1],
			  Stopbits: this.panel.TrSet[4],
			  Bits: this.panel.TrSet[2],
			  Parity: this.panel.TrSet[3],
			  Address: this.panel.Addr
			}
		  }else{
			data = {
			  ID: this.$store.getters["object/getObjectID"],
			  Model: null
			}
		  }
		  request(this.$store.getters['getBaseURL'] + '/ajax/object/save_panel', data).then(res => {
			this.isLoading = false;
			if (res.data.success) {
			  this.$root.$emit('notify', 'success', 'Панель сохранена');
			} else {
			  this.$root.$emit('notify', 'error', res.data.msg || 'Произошла ошибка');
			}
		  });
		},
		loadData: function () {
		  this.catalogs.Model = this.$store.getters['object/adminCatalog']('panelTypes');
		  this.catalogs.Model.unshift({ID: 0, PanelName: null, Model: null});
		  let data_panel = JSON.parse(this.$store.getters['object/adminData'].panel);
		  if (this.$store.getters['object/adminData'].panel != null) {
			this.panel = data_panel;
			this.panel.Model = Number(data_panel.Model);
			this.panel.Addr = data_panel.Addr;
			this.panel.TrSet[0] = Number(data_panel.TrSet[0]);
			this.panel.TrSet[1] = Number(data_panel.TrSet[1]);
			this.panel.TrSet[2] = Number(data_panel.TrSet[2]);
			this.panel.TrSet[3] = data_panel.TrSet[3];
			this.panel.TrSet[4] = Number(data_panel.TrSet[4]);
		  }
		},
	  },
	  mounted() {
		this.loadData();
	  },
	};
	</script>

	<style>
	* >> .v-label {
	  margin: 0;
	}

	.map-modal {
	  max-width: 60%;
	}

	@media (max-width: 576px) {
	  .map-modal {
		max-width: 100%;
	  }
	}
	</style>

	