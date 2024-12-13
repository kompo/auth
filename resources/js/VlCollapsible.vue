<template>
    <div v-bind="$_layoutWrapperAttributes" v-show="!$_hidden" @click="$_clickAction"
        :class="[expanded ? expandedClass : collapsedClass]" v-turbo-click="component.turbo">
        <div @click="toggleSubGroup" class="flex flex-between cursor-pointer items-center">
            <div v-bind="$_attributes(titleEl)" />

            <div v-html="icon" v-if="icon" :style="{
                transform: expanded ? 'rotate(180deg)' : 'rotate(0deg)',
                transition: 'transform 0.3s ease'
            }" />
        </div>
        <transition name="expand">
            <div v-show="expanded" :class="wrapperElementsClass || ''">
                <template v-for="row in elements">
                    <div v-bind="$_attributes(row)" />
                </template>
            </div>
        </transition>
    </div>
</template>

<script>
import Layout from 'vue-kompo/js/form/mixins/Layout'

export default {
    mixins: [Layout],
    data() {
        return {
            expanded: false,
        }
    },
    mounted() {
        setTimeout(() => {
            if (this.$_config('expandBasedInLinks') && this.links.some(link => window.location.origin + window.location.pathname == link)) {
                this.expanded = true;
            }
        }, 100);
    },
    computed: {
        titleEl() {
            return this.$_config('titleEl');
        },
        idSection() {
            return this.$_config('idSection');
        },
        icon() {
            return this.$_config('iconT');
        },
        expandedClass() {
            return (this.$_config('expandedClass') || 'expanded') + ' ' + this.$_classes;
        },
        collapsedClass() {
            return (this.$_config('collapsedClasses') || 'collapsed') + ' ' + this.$_classes;
        },
        wrapperElementsClass() {
            return this.$_config('wrapperElementsClass');
        },
        links() {
            return this.$_config('links').split(',');
        },
    },
    methods: {
        toggleSubGroup() {
            this.expanded = !this.expanded;
        },
    }
}
</script>

<style>
.expand-enter-active, .expand-leave-active {
    transition: max-height 0.35s ease, opacity 0.35s ease;
}

.expand-enter, .expand-leave-to {
    max-height: 0;
    opacity: 0;
}

.expand-enter-to, .expand-leave {
    max-height: 1000px;
    opacity: 1;
}
</style>
