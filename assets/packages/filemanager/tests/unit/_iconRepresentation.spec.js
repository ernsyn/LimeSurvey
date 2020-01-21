import {
    shallowMount,
    createLocalVue
} from '@vue/test-utils';

import Vuex from 'vuex';
import _ from 'lodash';

import IconRepComponent from '../../src/components/subcomponents/_iconRepresentation.vue';
import VueXMutations from '../../src/storage/mutations.js';
import MockState from '../mocks/mockState.js';
import MockActions from '../mocks/mockActions.js';

const localVue = createLocalVue();
localVue.use(Vuex);

localVue.mixin({
    methods: {
        translate(value) {
            return value;
        }
    },
    filters: {
        translate: (value) => {
            return value;
        }
    }
});


describe("correct display", () => {
    const actions = _.clone(MockActions);
    const state = _.clone(MockState);
    const store = new Vuex.Store({
        state,
        mutations: VueXMutations,
        actions
    });

    const iconRepMount = shallowMount(IconRepComponent, {
        propsData: { 
            loading: false
        },
        mocks: {
            $dialog: {
                confirm: jest.fn((txt) => {
                return Promise.resolve();
            })},
            $log: {log: jest.fn()}
        },
        store,
        localVue
    }); 

    test.each(_.toArray(MockState.fileList))(
        "Has an image block for every file rendered", 
        (file) => {
            const iconRepContainer = iconRepMount.find('#iconRep-' + file.hash);
        expect(iconRepContainer.html()).toContain(`<img src="${file.src}" alt="${file.shortName}" class="scoped-contain-image">`)
        && expect(iconRepContainer.html()).toContain(`<small>{{file.size | bytes}}</small>`)
        && expect(iconRepContainer.html()).toContain(file.shortName)
        && expect(iconRepContainer.html()).toContain(`<small>${IconRepComponent.filters.bytes(file.size)}</small>`)
        && expect(iconRepContainer.html()).toContain(`<small>${file.mod_time}</small>`)
        && expect(iconRepContainer.html()).toContain(`<button class="FileManager--file-action-delete btn btn-default" title="Delete file" data-toggle="tooltip"><i class="fa fa-trash-o text-danger"></i></button>`)
        && expect(iconRepContainer.html()).toContain(`<button class="FileManager--file-action-startTransit-copy btn btn-default" title="Copy file" data-toggle="tooltip"><i class="fa fa-clone"></i></button>`)
        && expect(iconRepContainer.html()).toContain(`<button class="FileManager--file-action-startTransit-move btn btn-default" title="Move file" data-toggle="tooltip"><i class="fa fa-files-o"></i></button>`);
    });

    test("Has no file in transit", () => {
        expect(iconRepMount.find('.FileManager--file-action-cancelTransit').exists()).toBe(false);
    });

    test.each(_.toArray(MockState.fileList))(
        "Has an image block for every file rendered", 
        (file) => {
            const iconRepContainer = iconRepMount.find('#iconRep-' + file.hash);
        expect(iconRepContainer.find('.FileManager--file-action-delete').exists()).toBe(true);
    });

    test.each(_.toArray(MockState.fileList))(
        "Has an image block for every file rendered", 
        (file) => {
            const iconRepContainer = iconRepMount.find('#iconRep-' + file.hash);
        expect(iconRepContainer.find('.FileManager--file-action-startTransit-copy').exists()).toBe(true);
    });

    test.each(_.toArray(MockState.fileList))(
        "Has an image block for every file rendered", 
        (file) => {
            const iconRepContainer = iconRepMount.find('#iconRep-' + file.hash);
        expect(iconRepContainer.find('.FileManager--file-action-startTransit-move').exists()).toBe(true);
    });
    
    test("has a working byte-filter", () => {
        let shouldBeKB = IconRepComponent.filters.bytes(1025);
        let shouldBeMB = IconRepComponent.filters.bytes(1048577);
        expect(shouldBeKB).toBe('1 KB')
        && expect(shouldBeMB).toBe('1 MB');
    });

    test("has correct max height set", () => {

    })
}); 

describe("File transit actions", () => {
    
    const actions = _.clone(MockActions);
    const fileInTransit = MockState.fileList['firstPicture.jpg'];
    const fileNotTransit = MockState.fileList['secondPicture.jpg'];
    
    let iconRepMount;
    beforeEach(() => {
        const state = _.clone(MockState);
        
        const store = new Vuex.Store({
            state,
            mutations: VueXMutations,
            actions
        });

        iconRepMount = shallowMount(IconRepComponent, {
            propsData: { 
                loading: false
            },
            mocks: {
                $dialog: {
                    confirm: jest.fn((txt) => {
                    return Promise.resolve();
                })},
                $log: {log: jest.fn()}
            },
            store,
            localVue
        }); 
    }); 

    test("Should start transit after clicking on 'copy'", () => {
        const iconRepContainer = iconRepMount.find("#iconRep-" + fileInTransit.hash);
        const copyButton = iconRepContainer.find('.FileManager--file-action-startTransit-copy');
        copyButton.trigger('click');
        expect(iconRepMount.vm.inTransit(fileInTransit)).toBe(true);  
    })

    test("Should start transit after clicking on 'move'", () => {
        const iconRepContainer = iconRepMount.find("#iconRep-" + fileInTransit.hash);
        const copyButton = iconRepContainer.find('.FileManager--file-action-startTransit-move');
        copyButton.trigger('click');
        expect(iconRepMount.vm.inTransit(fileInTransit)).toBe(true);  
    })
});

describe("File in transit actions", () => {
    
    const actions = _.clone(MockActions);
    const fileInTransit = MockState.fileList['firstPicture.jpg'];
    const fileNotTransit = MockState.fileList['secondPicture.jpg'];
    const state = _.clone(MockState);

    let iconRepMount;
    beforeEach(() => {

        state.fileInTransit = fileInTransit;
        state.transitType = "move";

        const store = new Vuex.Store({
            state,
            mutations: VueXMutations,
            actions
        });

        iconRepMount = shallowMount(IconRepComponent, {
            propsData: { 
                loading: false
            },
            mocks: {
                $dialog: {
                    confirm: jest.fn((txt) => {
                    return Promise.resolve();
                })},
                $log: {log: jest.fn()}
            },
            store,
            localVue
        }); 
    }); 

    test("Should show cancel transit button when a transit starts", () => {
        const iconRepContainer = iconRepMount.find("#iconRep-" + fileInTransit.hash);
        expect(iconRepContainer.find('.FileManager--file-action-cancelTransit').exists()).toBe(true);
    });
    test("Should cancel the transit after clickong 'cancelTransit'", () => {
        const iconRepContainer = iconRepMount.find("#iconRep-" + fileInTransit.hash);
        iconRepContainer.find('.FileManager--file-action-cancelTransit').trigger('click');
        expect(iconRepMount.vm.inTransit(fileInTransit)).toBe(false);
    })

    test("Should mark in transit file as inTransit", () => {
        expect(iconRepMount.vm.inTransit(fileInTransit)).toBe(true);
    })
    test("Should mark notInTransitFile as notInTransit", () => {
        expect(iconRepMount.vm.inTransit(fileNotTransit)).toBe(false);
    })

    test("Should have the correct classes for a file in transit", () => {
        const fileRowClasses = iconRepMount.vm.fileClass(fileInTransit);
        expect(fileRowClasses).toBe("scoped-file-icon file-in-transit move ")
    })
    test("Should have the correct classes for a file not in transit", () => {
        const fileRowClasses = iconRepMount.vm.fileClass(fileNotTransit);
        expect(fileRowClasses).toBe("scoped-file-icon ")
    })

    
});

describe('Delete file success', () => {
    const fileToBeDeleted = MockState.fileList['firstPicture.jpg'];
    const state = _.clone(MockState);
    const callDialog = jest.fn((txt) => Promise.resolve(txt));

    let actions;
    let iconRepMount;
    beforeAll(() => {

        actions = _.clone(MockActions);
        actions.deleteFile = jest.fn();

        const store = new Vuex.Store({
            state,
            mutations: VueXMutations,
            actions
        });

        iconRepMount = shallowMount(IconRepComponent, {
            propsData: { 
                loading: false
            },
            mocks: {
                $dialog: {
                    confirm: callDialog
                },
                $log: {log: ()=>{}, error: ()=>{}}
            },
            store,
            localVue
        }); 
    }); 

    test("Should call a dialog on click on delete file", () => {
        const iconRepContainer = iconRepMount.find("#iconRep-" + fileToBeDeleted.hash);
        iconRepContainer.find('.FileManager--file-action-delete').trigger('click');
        expect(callDialog).toHaveBeenCalled();
    });
    test("Should have called the delete action after clicking delete", () => {
        expect(actions.deleteFile).toHaveBeenCalled()
    });
    
})

describe('Delete file failure', () => {
    const fileToBeDeleted = MockState.fileList['firstPicture.jpg'];
    const state = _.clone(MockState);
    const callDialog = jest.fn((txt) => Promise.reject());
    let actions;
    let iconRepMount;

    beforeEach(() => {
        actions = _.clone(MockActions);
        actions.deleteFile = jest.fn(() => Promise.resolve());

        const store = new Vuex.Store({
            state,
            mutations: VueXMutations,
            actions
        });

        iconRepMount = shallowMount(IconRepComponent, {
            propsData: { 
                loading: false
            },
            mocks: {
                $dialog: {
                    confirm: callDialog
                },
                $log: {log: ()=>{}, error: ()=>{}}
            },
            store,
            localVue
        }); 
    }); 

    test("Should not call the delete action after clicking delete", () => {
        const iconRepContainer = iconRepMount.find("#iconRep-" + fileToBeDeleted.hash);
        iconRepContainer.find('.FileManager--file-action-delete').trigger('click');
        expect(actions.deleteFile).not.toHaveBeenCalled()
    });
}); 
