/**
 * CoPAI Platform
 * https://copai.community
 *
 * Developed by Murbit GmbH as part of the Erasmus+ project:
 *
 * Community of Practice AI
 * Project No.: KA210-VET-4603C73C
 *
 * Funded by the European Union. Views and opinions expressed are however
 * those of the author(s) only and do not necessarily reflect those of the
 * European Union or the European Education and Culture Executive Agency (EACEA).
 * Neither the European Union nor EACEA can be held responsible for them.
 *
 * Copyright (c) 2025 Murbit GmbH
 *
 * Licensed under the MIT License.
 * See LICENSE file for details.
 */

( function ( wp ) {
    var el                 = wp.element.createElement;
    var registerBlockType  = wp.blocks.registerBlockType;
    var useBlockProps      = wp.blockEditor.useBlockProps;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var PanelBody          = wp.components.PanelBody;
    var RangeControl       = wp.components.RangeControl;
    var SelectControl      = wp.components.SelectControl;
    var ServerSideRender   = wp.serverSideRender;
    var __                 = wp.i18n.__;

    registerBlockType( 'copai/meetup-list', {
        edit: function ( props ) {
            var attributes    = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps    = useBlockProps();

            return el(
                'div',
                blockProps,
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __( 'Einstellungen', 'copai-meetup-block' ), initialOpen: true },
                        el( RangeControl, {
                            label:    __( 'Anzahl Meetups', 'copai-meetup-block' ),
                            value:    attributes.count,
                            min:      1,
                            max:      50,
                            onChange: function ( v ) { setAttributes( { count: v } ); }
                        } ),
                        el( SelectControl, {
                            label:   __( 'Zeitraum', 'copai-meetup-block' ),
                            value:   attributes.scope,
                            options: [
                                { label: __( 'Kommende', 'copai-meetup-block' ),  value: 'upcoming' },
                                { label: __( 'Vergangene', 'copai-meetup-block' ), value: 'past' }
                            ],
                            onChange: function ( v ) { setAttributes( { scope: v } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block:      'copai/meetup-list',
                    attributes: attributes
                } )
            );
        },

        save: function () {
            // Dynamic block — output comes from render.php
            return null;
        }
    } );
} )( window.wp );
