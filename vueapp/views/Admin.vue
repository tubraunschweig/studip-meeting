<template>
    <div>
        <h1 v-translate>Meetings konfigurieren</h1>

        <MessageList :messages="message ? [message] : []" />

        <MessageBox v-if="changes_made" type="warning">
            <translate>
                Ihre Änderungen sind noch nicht gespeichert!
            </translate>
        </MessageBox>

        <form class="default" v-if="drivers" @submit.prevent>
            <fieldset>
                <legend v-translate>
                    Allgemeine Konfiguration
                </legend>
                <label>
                    <translate>Feedback Support-Adresse</translate>
                    <input type="text" v-model.trim="general_config['feedback_contact_address']">
                </label>
                <label>
                    <translate>Feedback Absenderadresse</translate>
                    <br>
                    <input type="radio"
                        value="standard_mail"
                        v-model="general_config['feedback_sender_address']">
                    <translate>Standard-Mail</translate>
                    <input type="radio"
                        value="user_mail"
                        v-model="general_config['feedback_sender_address']">
                    <translate>User-Mail</translate>
                </label>

                <label>
                    <translate>Stud.IP für Standardfolien verwenden</translate>
                    <br>
                    <input type="radio"
                        value="studip"
                        v-model="general_config['read_default_slides_from']">
                    <translate>Ja</translate>
                    <input type="radio"
                        value="server"
                        v-model="general_config['read_default_slides_from']">
                    <translate>Nein</translate>
                </label>
                
                <StudipButton style="margin-top: 0;" :disabled="general_config['read_default_slides_from'] == 'server'"
                    @click="defaultSlideManagerShow = !defaultSlideManagerShow">
                    <translate>Standard-Folie verwalten</translate>
                </StudipButton>

                <DefaultSlideManagerDialog v-if="defaultSlideManagerShow" @close="defaultSlideManagerShow = false"/>
            </fieldset>
            <fieldset v-for="(driver, driver_name) in drivers" :key="driver_name">
                <legend>
                    {{ driver.title }}
                </legend>
                <label>
                        <input type="checkbox"
                        true-value="1"
                        false-value="0"
                        v-model="config[driver_name]['enable']">
                        <translate>Verwenden dieses Treibers zulassen</translate>
                </label>

                <label v-if="Object.keys(config[driver_name]).includes('display_name')">
                    <translate>Anzeigename</translate>
                    <input type="text" v-model.trim="config[driver_name]['display_name']">
                </label>

                <div v-if="Object.keys(driver).includes('recording')">
                    <label v-for="(rval, rkey) in driver['recording']" :key="rkey">
                        <input v-if="typeof rval['value'] == 'boolean'" type="checkbox"
                        true-value="1"
                        false-value="0"
                        @click="handleRecordings(driver_name, rval['name'])"
                        v-model="config[driver_name][rval['name']]">
                        <span>
                            {{ rval['display_name'] }}
                        </span>

                        <StudipTooltipIcon v-if="Object.keys(rval).includes('info')" :text="rval['info']">
                        </StudipTooltipIcon>
                    </label>
                </div>

                <div v-if="Object.keys(driver).includes('preupload')">
                    <label v-for="(rval, rkey) in driver['preupload']" :key="rkey">
                        <input v-if="typeof rval['value'] == 'boolean'" type="checkbox"
                        true-value="1"
                        false-value="0"
                        v-model="config[driver_name][rval['name']]">
                        <span :class="{'disabled': rval['name'] != 'preupload' && config[driver_name]['preupload'] != '1'}">
                            {{ rval['display_name'] }}
                        </span>

                        <StudipTooltipIcon v-if="Object.keys(rval).includes('info')" :text="rval['info']">
                        </StudipTooltipIcon>
                    </label>
                </div>

                <label v-if="Object.keys(config[driver_name]).includes('welcome')">
                    <translate>Willkommensnachricht</translate>
                    <textarea v-model="config[driver_name]['welcome']" cols="30" rows="5"></textarea>
                </label>

                <div v-if="config[driver_name].servers && Object.keys(config[driver_name].servers).length && server_object[driver_name]">
                    <h3 v-translate>
                        Folgende Server werden verwendet
                    </h3>
                    <table class="default collapsable tablesorter conference-meetings">
                        <thead>
                            <tr>
                                <th>#</th>
                                <template v-for="(value, key) in driver.config">
                                    <th v-if="value.name != 'roomsize-presets' && value.name != 'description' && (!value.attr || value.attr != 'password')" :key="key"
                                    :class="{td_center:value.name == 'active'}"
                                    :title="value.display_name">
                                         {{ value.display_name }}
                                    </th>
                                </template>
                                <th v-translate>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(server, index) in config[driver_name].servers" :key="index"
                                :class="{'active nohover': (server_object[driver_name]['index'] == index)}">
                                <td>{{ index + 1 }}</td>
                                <template v-for="(value, key) in driver.config">
                                    <td :key="key" v-if="value.name && value.name != 'roomsize-presets' && value.name != 'description' && (!value.attr || value.attr != 'password')"
                                    :class="{td_center:value.name == 'active'}"
                                    :title="(value.name != 'active' && value.name != 'course_types' ? server[value.name] : '')"
                                    >
                                        <template v-if="value.name == 'maxParticipants'
                                                && (!(server[value.name]) || parseInt(server[value.name]) == 0)"
                                            v-translate
                                        >
                                            Ohne Grenze
                                        </template>
                                        <template v-else-if="value.name == 'course_types'" v-translate>
                                            {{ getCourseTypeName(server[value.name], driver_name) }}
                                        </template>
                                        <template v-else-if="value.name == 'active'" v-translate>
                                            <StudipIcon :icon="(server[value.name]) ? 'checkbox-checked' : 'checkbox-unchecked'"
                                                role="clickable" size="14"></StudipIcon>
                                        </template>
                                        <template v-else>
                                            {{ server[value.name] }}
                                        </template>
                                    </td>
                                </template>
                                <td>
                                    <a style="cursor: pointer;" @click.prevent="prepareEditServer(driver_name, index)">
                                        <StudipIcon icon="edit" role="clickable" ></StudipIcon>
                                    </a>
                                    <a style="cursor: pointer;" @click.prevent="deleteServer(driver_name, index)">
                                        <StudipIcon icon="trash" role="clickable"></StudipIcon>
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <StudipButton
                    icon="add"
                    @click="addServerDialog(driver_name)">
                    <translate>Server hinzufügen</translate>
                </StudipButton>

                <ServerDialog
                    v-if="server_object[driver_name]"
                    :DialogVisible="serverDialogVisible == driver_name"
                    :server_object="server_object"
                    :driver_name="driver_name"
                    :driver="driver"
                    @close="serverDialogVisible = false"
                    @edit="addEditServers"
                />

            </fieldset>

            <MessageList :messages="message ? [message] : []" />

            <MessageBox v-if="changes_made" type="warning">
                <translate>
                    Ihre Änderungen sind noch nicht gespeichert!
                </translate>
            </MessageBox>

            <footer>
                <StudipButton icon="accept"
                    :class="{
                        'disabled': !changes_made
                    }"
                    @click="storeConfig"
                >
                    <translate>Einstellungen speichern</translate>
                </StudipButton>
            </footer>
        </form>
    </div>
</template>

<script>
import { mapGetters } from "vuex";
import store from "@/store";

import StudipButton from "@/components/StudipButton";
import StudipTooltipIcon from "@/components/StudipTooltipIcon";
import StudipIcon from "@/components/StudipIcon";
import MessageBox from "@/components/MessageBox";
import MessageList from "@/components/MessageList";
import ServerDialog from "@/components/ServerDialog";
import DefaultSlideManagerDialog from "@/components/DefaultSlideManagerDialog";

import {
    CONFIG_LIST_READ,
    CONFIG_CREATE, CONFIG_DELETE
} from "@/store/actions.type";

import {
    CONFIG_SET,
} from "@/store/mutations.type";

export default {
    name: "Admin",

    components: {
        StudipButton,
        StudipTooltipIcon,
        MessageBox,
        MessageList,
        StudipIcon,
        ServerDialog,
        DefaultSlideManagerDialog
    },

    data() {
        return {
            message: null,
            server_object: {},
            serverDialogVisible: false,
            changes_made: false,
            defaultSlideManagerShow: false
        }
    },

    computed: {
        ...mapGetters(['config', 'drivers', 'general_config'])
    },

    methods: {
        storeConfig() {
            this.$store.dispatch(CONFIG_CREATE, {'config': this.config, 'general_config': this.general_config})
                .then(({ data }) => {
                    this.message = data.message;
                    this.$store.commit(CONFIG_SET, data.config);

                    if (data.message.type == 'error') {
                       this.$store.dispatch(CONFIG_LIST_READ)
                            .then(() => {
                                this.changes_made = false;
                                this.createServerObject();
                            });
                    }
                    this.changes_made = false;
                });
        },

        deleteServer(driver_name, index) {
            this.$delete(this.config[driver_name]['servers'], index);
        },

        clearServer(driver_name) {
            for (var key in this.server_object[driver_name]) {
                //reset server_object values
                if (key != 'index') {
                    this.server_object[driver_name][key] = "";
                } else {
                    this.server_object[driver_name][key] = -1;
                }
                // Pre-define active param.
                this.$set(this.server_object[driver_name], 'active', true);
            }
        },

        addServerDialog(driver_name) {
            this.clearServer(driver_name);
            this.serverDialogVisible = driver_name;
        },

        addEditServers(params) {
            //this.changes_made = true;

            let driver_name   = params.driver_name;
            let server_object = params.server;

            if (!this.config[driver_name]['servers']) {
                this.$set(this.config[driver_name], 'servers', []);
            }

            var index = 0;
            // manage the index of the array
            if (server_object[driver_name]['index'] == -1) { //new
                index = Object.keys(this.config[driver_name]['servers']).length;
            } else { //edit
                index = server_object[driver_name]['index'];
            }
            // reset the index
            server_object[driver_name]['index'] = -1;

            //create a new object out of server_object (without index)
            var new_server_object = new Object();
            for (var key in server_object[driver_name]) {
                if (key != 'index') {
                    new_server_object[key] = server_object[driver_name][key];
                    //reset server_object values
                    server_object[driver_name][key] = "";
                }
            }
            //push to the servers array
            this.$set(this.config[driver_name]['servers'], index , new_server_object);
            this.serverDialogVisible = false;
        },

        prepareEditServer(driver_name, index) {
            var current_obj = this.config[driver_name]['servers'][index];
            for (var key in current_obj) {
                this.server_object[driver_name][key] = current_obj[key];
            }
            this.server_object[driver_name]['index'] = index;

            this.serverDialogVisible = driver_name;
        },

        createServerObject() {
            for (var driver_name in this.drivers) {
                var server_config = new Object();
                server_config.index = -1;
                this.$set(this.server_object, driver_name, server_config);
            }
        },

        handleRecordings(driver_name, recording_option) {
            // We want to allow only "opencast" or "record" as recording option to be enabled at the same time!
            setTimeout(() => {
                if (this.config[driver_name][recording_option] && this.config[driver_name][recording_option] == '1') {
                    if (recording_option == 'opencast' && this.config[driver_name]['record']) { // If opencast going to be enabled, record must be disabled.
                        this.$set(this.config[driver_name], 'record', '0');
                    } else if (recording_option == 'record' && this.config[driver_name]['opencast']) {// If record going to be enabled, opencast must be disabled.
                        this.$set(this.config[driver_name], 'opencast', '0');
                    }
                }
            }, 100);
        },

        getCourseTypeName(type_id, driver_name) {
            if (!type_id) {
                return 'Alle';
            }
            type_id = type_id + '';
            var class_id = type_id.split('_')[0];
            var config_course_types = this.drivers[driver_name]['config'].find( cf => { return cf.name == 'course_types'});
            if (config_course_types.value && config_course_types.value[class_id] && config_course_types.value[class_id]['subs'] && config_course_types.value[class_id]['subs'][type_id]) {
                return config_course_types.value[class_id]['subs'][type_id];
            } else {
                return 'Unbekannt';
            }
        }
    },

    watch: {
        config: {
            handler: function() {
                this.changes_made = true;
            },
            deep: true
        },
        general_config: {
            handler: function() {
                this.changes_made = true;
            },
            deep: true
        }
    },

    mounted() {
        store.dispatch(CONFIG_LIST_READ)
            .then(() => {
                this.changes_made = false;
                this.createServerObject();
            });
    }
};
</script>
