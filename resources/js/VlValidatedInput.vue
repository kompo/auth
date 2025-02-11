<template>
    <vl-form-field v-bind="$_wrapperAttributes">
        <div :class="'vlInputGroup rounded-lg ' + (showError ? invalidClass : '')">
            <input :value="visualValue" class="vlFormControl" v-bind="$_attributes" v-on="$_events"
                @input="onInputValue" @focus="onFocus" @blur="focused = false" ref="input" />
            <div class="justify-center flex items-center px-4" v-if="showError">
                <span class="invalid-icon">!</span>
            </div>
        </div>
    </vl-form-field>
</template>

<script>
import Field from 'vue-kompo/js/form/mixins/Field'
import HasInputAttributes from 'vue-kompo/js/form/mixins/HasInputAttributes'

export default {
    mixins: [Field, HasInputAttributes],
    data() {
        return {
            rawValue: this.component?.value || '',
            pastValue: this.component?.value || '',
            focused: false,
            dirty: false
        }
    },
    watch: {
        'component.value'(value) {
            this.rawValue = value ?? '';
            this.pastValue = value ?? '';
        }
    },  
    computed: {
        visualValue() {
            return this.formatToModel(this.rawValue)
        },
        $_attributes() {
            return {
                ...this.$_defaultFieldAttributes,
                ...this.$_defaultInputAttributes
            }
        },
        focusOnLoad() { return this.$_config('focusOnLoad') },
        invalidClass() { return this.$_config('invalidClass') },
        formatModels() { return this.$_config('formatModels') },
        validateFormat() { return this.$_config('validateFormat') },
        allowFormat() { return this.$_config('allowFormat') },
        showError() {
            return !this.isValidValue && !this.focused && this.dirty;
        },
        isValidValue() {
            return (new RegExp(this.validateFormat)).test(this.component?.value || '')
        }
    },
    methods: {
        focus() {
            this.$refs.input.focus()
        },
        onInputValue(e) {
            const lastChar = e.data;

            if (lastChar === null) {
                this.rawValue = this.rawValue.slice(0, -1);
            } else {
                this.rawValue = this.rawValue + lastChar;
            }
            if (!(new RegExp(this.allowFormat)).test(this.rawValue || '')) {
                this.rawValue = this.pastValue;
            }

            this.component.value = this.rawValue;
            this.pastValue = this.rawValue;

        },
        onFocus() {
            this.focused = true;
            this.dirty = true;
        },
        formatToModel(value) {
            if (!value) return '';

            if (this.formatModels && Object.entries(this.formatModels).length > 0) {
                Object.entries(this.formatModels).forEach(([from, to]) => {
                    value = value.replace(new RegExp(from), to)
                })
            }
            return value;
        }
    },
    mounted() {
        if (this.focusOnLoad)
            this.focus()
    },
}
</script>

<style scoped>
.invalid-icon {
    color: red;
    font-weight: bold;
    margin-left: 8px;
    cursor: pointer;
}
</style>