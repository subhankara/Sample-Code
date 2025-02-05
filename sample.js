/**
 * Claiming Module Class Init
 */
class ClaimingModuleClass {
    constructor(config = {}) {
        // Default settings for the claiming module
        this.defaults = {
            text: 'Claim now!',
            color: '#fff',
            'background-color': '#3AA1FF',
            'button-size': 'm',
            'button-shadow': '',
            'addi-entry': '',
            'email-entry-text': '',
            'term-condition-text': ''
        };
        
        // Merge default settings with provided config
        this.config = { ...this.defaults, ...config };
        this.fields = []; // Stores form fields for dynamic updates
        this.spectrumTargets = []; // Spectrum (color picker) fields
        this.select2Targets = [];  // Select2 dropdown fields
        
        // Initialize various components
        this.initSpectrum();
        this.initSelect2();
        this.loadDefaults();
        this.generate(); // Generate the preview button
        this.initButtonCheckBox();
        this.handleAdditionalValueChange();
        this.setupFormValidation();
    }

    /**
     * Initialize button border radius functionality
     */
    initButtonCheckBox() {
        jQuery('.border-radius').on('change', () => this.generate());
    }

    /**
     * Initialize Spectrum color pickers
     * @param {Array} spectrumInputs - List of input IDs to attach Spectrum
     */
    initSpectrum(spectrumInputs = ['color', 'background-color']) {
        spectrumInputs.forEach((id) => {
            this.spectrumTargets.push(id);
            jQuery(`#${id}`).spectrum(this.getSpectrumConfig('black'));
        });
    }

    /**
     * Initialize Select2 dropdowns
     * @param {Array} select2Inputs - List of input IDs to attach Select2
     */
    initSelect2(select2Inputs = ['button-shadow', 'button-size']) {
        select2Inputs.forEach((id) => {
            this.select2Targets.push(id);
            jQuery(`#${id}`).select2(this.getSelect2Config());
        });
    }

    /**
     * Spectrum configuration generator
     * @param {string} color - Default color
     * @returns {Object} Spectrum configuration
     */
    getSpectrumConfig(color) {
        return {
            color,
            showInput: true,
            preferredFormat: 'hex',
            change: () => this.generate() // Trigger preview update on color change
        };
    }

    /**
     * Select2 configuration generator
     * @returns {Object} Select2 configuration
     */
    getSelect2Config() {
        return {
            minimumResultsForSearch: -1 // Disable search box in Select2
        };
    }

    /**
     * Load default values into the form fields
     */
    loadDefaults() {
        Object.entries(this.config).forEach(([key, value]) => {
            const $target = jQuery(`#${key}`);
            this.fields.push($target); // Store the field for later use

            if (this.spectrumTargets.includes(key)) {
                $target.spectrum('set', value); // Set Spectrum color
            } 
            else if (key === 'addi-entry' && value.length > 0) {
                // Handle additional entry checkboxes
                value.forEach((data) => {
                    jQuery(`#${data}-entry`).prop('checked', true);
                    setTimeout(() => jQuery(`#addi-${data}`).toggle('slow'), 1);
                });
            } 
            else if (['button-shadow', 'button-size'].includes(key)) {
                $target.val(value).trigger('change'); // Initialize Select2 with value
            } 
            else {
                $target.val(value); // Set text or other field values
            }

            // Attach input event listener for real-time updates
            $target.on('input', () => this.generate());
        });
    }

    /**
     * Generate the button preview based on current form values
     */
    generate() {
        const results = {};
        this.fields.forEach(($field) => {
            results[$field.attr('id')] = $field.val();
        });

        const btnRadius = jQuery('input[name="c1"]:checked').val() || 0;

        // Dynamic button HTML with applied styles
        const output = `
            <a href="#" 
                style="line-height: normal; color:${results.color}; background: ${results['background-color']}; 
                border-radius: ${btnRadius}px;" 
                class="claim-btn size-${results['button-size']} ${results['button-shadow']}">
                ${results.text}
            </a>
        `;

        // Inject the button into the preview area
        jQuery('.preview-area').html(output);
    }

    /**
     * Toggle additional input fields based on checkbox selection
     */
    handleAdditionalValueChange() {
        jQuery('.additionalSelect').on('click', function () {
            const inputValue = jQuery(this).attr('value');
            jQuery(`#addi-${inputValue}`).toggle('slow');
        });
    }

    /**
     * Setup form validation using jQuery Validate plugin
     */
    setupFormValidation() {
        jQuery("#claiming-module-form").validate({
            ignore: ":hidden",
            rules: {
                text: {
                    required: true // Claim button text is required
                },
                addi_terms: {
                    required: function () {
                        return jQuery("#terms-entry:checked").length > 0;
                    }
                }
            },
            submitHandler: (form) => {
                const formData = new FormData(form);
                const $claimingBtn = jQuery(".claimingBtn");

                // Disable button and show loading state
                $claimingBtn.text('Updating.....').addClass('btn-disabled');
                
                // AJAX request to submit form data
                jQuery.ajax({
                    url: ajaxData.ajaxurl,
                    type: "POST",
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: (response) => {
                        if (response.success) {
                            ShowNotice(response.data.message, "success", () => {
                                if (response.data.type === 'continue') {
                                    reload_screen(response.data.redirect); // Redirect if needed
                                } else {
                                    $claimingBtn.text('Update').removeClass('btn-disabled'); // Re-enable button
                                }
                            });
                        }
                    }
                });

                return false; // Prevent default form submission
            },
            highlight: function (element) {
                jQuery(element).parent().addClass('error-handle'); // Highlight invalid fields
            },
            unhighlight: function (element) {
                jQuery(element).parent().removeClass('error-handle'); // Remove highlight from valid fields
            }
        });
    }
}

/**
 * The Usage is bellow
 * All variables are dynamic
 */
var config = new Map([
    ['text', text],          
    ['color', color],  
    ['background-color', bgcolor],  
    ['button-size', size], 
    ['button-shadow', shadow], 
    ['addi-entry', addi_entry.split(',')],
    ['email-entry-text', email_entry_text],
    ['term-condition-text', term_conditions_text]
]);
new ClaimingModuleClass(config);