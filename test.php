<?php	public function requestContact(Request $request){
		
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

	