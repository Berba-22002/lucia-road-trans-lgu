// Resident name autocomplete - allows free text if no resident found
document.getElementById('offender_name').addEventListener('input', async function() {
    const query = this.value.trim();
    const suggestionsDiv = document.getElementById('residentSuggestions');
    
    if (query.length < 2) {
        suggestionsDiv.classList.add('hidden');
        document.getElementById('residentInfo').classList.add('hidden');
        document.getElementById('resident_id').value = '';
        return;
    }
    
    try {
        const response = await fetch(`../api/search_residents.php?q=${encodeURIComponent(query)}`);
        const residents = await response.json();
        
        if (residents.length > 0) {
            suggestionsDiv.innerHTML = residents.map(r => `
                <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer border-b text-sm" onclick="selectResident('${r.id}', '${r.fullname}')">
                    <div class="font-semibold text-lgu-headline">${r.fullname}</div>
                    <div class="text-xs text-lgu-paragraph">ID: ${r.id}</div>
                </div>
            `).join('');
            suggestionsDiv.classList.remove('hidden');
        } else {
            suggestionsDiv.innerHTML = '<div class="px-4 py-2 text-sm text-lgu-paragraph italic">No residents found - you can enter a custom name</div>';
            suggestionsDiv.classList.remove('hidden');
            document.getElementById('residentInfo').classList.add('hidden');
            document.getElementById('resident_id').value = '';
        }
    } catch (error) {
        console.error('Search error:', error);
    }
});

document.getElementById('offender_name').addEventListener('blur', function() {
    const suggestionsDiv = document.getElementById('residentSuggestions');
    setTimeout(() => suggestionsDiv.classList.add('hidden'), 200);
});

function selectResident(id, name) {
    document.getElementById('offender_name').value = name;
    document.getElementById('resident_id').value = id;
    document.getElementById('residentIdDisplay').textContent = id;
    document.getElementById('residentInfo').classList.remove('hidden');
    document.getElementById('residentSuggestions').classList.add('hidden');
}
