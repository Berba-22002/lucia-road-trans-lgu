async function loadResidents() {
    const residents = <?php 
        try {
            $database = new Database();
            $pdo = $database->getConnection();
            $stmt = $pdo->prepare("SELECT id, fullname, contact_number as contact FROM users WHERE role = 'resident' ORDER BY fullname ASC");
            $stmt->execute();
            $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($residents);
        } catch (Exception $e) {
            echo json_encode([]);
        }
    ?>;
    
    const dropdown = document.getElementById('residentDropdown');
    dropdown.innerHTML = '<option value="">Select a resident...</option>';
    
    if (Array.isArray(residents) && residents.length > 0) {
        residents.forEach(resident => {
            const option = document.createElement('option');
            option.value = resident.id;
            option.textContent = resident.fullname + ' (ID: ' + resident.id + ')';
            option.dataset.contact = resident.contact || '';
            dropdown.appendChild(option);
        });
    } else {
        dropdown.innerHTML = '<option value="">No residents found</option>';
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
