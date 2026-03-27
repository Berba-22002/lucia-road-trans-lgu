async function loadResidents() {
    try {
        const response = await fetch('../api/get_all_residents.php');
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        const dropdown = document.getElementById('residentDropdown');
        dropdown.innerHTML = '<option value="">Select a resident...</option>';
        
        if (Array.isArray(data) && data.length > 0) {
            data.forEach(resident => {
                const option = document.createElement('option');
                option.value = resident.id;
                option.textContent = resident.fullname + ' (ID: ' + resident.id + ')';
                option.dataset.contact = resident.contact || '';
                dropdown.appendChild(option);
            });
        } else {
            dropdown.innerHTML = '<option value="">No residents found</option>';
        }
    } catch (error) {
        console.error('Error loading residents:', error);
        document.getElementById('residentDropdown').innerHTML = '<option value="">Error: ' + error.message + '</option>';
    }
}

document.getElementById('residentDropdown').addEventListener('change', function() {
    if (this.value) {
        const selectedOption = this.options[this.selectedIndex];
        document.getElementById('resident_id').value = this.value;
        updateOffenderNameField();
        if (selectedOption.dataset.contact) {
            document.getElementById('offender_contact').value = selectedOption.dataset.contact;
        }
    } else {
        document.getElementById('resident_id').value = '';
        document.getElementById('offender_contact').value = '';
        updateOffenderNameField();
    }
});

document.getElementById('offender_name_custom').addEventListener('input', function() {
    updateOffenderNameField();
});
