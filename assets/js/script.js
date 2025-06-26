 
document.addEventListener('DOMContentLoaded', function() {
    // Confirm before rejecting a weekoff request
    const rejectForms = document.querySelectorAll('form button[type="submit"][name="reject"]');
    rejectForms.forEach(button => {
        button.addEventListener('click', function(e) {
            const form = this.closest('form');
            const remarks = form.querySelector('input[name="remarks"]').value;
            if (!remarks) {
                e.preventDefault();
                alert('Please enter a reason for rejection');
                return false;
            }
            return confirm('Are you sure you want to reject this weekoff request?');
        });
    });
    
    // Date validation for weekoff request
    const dateInput = document.querySelector('input[name="weekoff_date"]');
    if (dateInput) {
        dateInput.addEventListener('change', function() {
            const selectedDate = new Date(this.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate < today) {
                alert('You cannot request weekoff for past dates');
                this.value = '';
            }
        });
    }
    
    // Toggle mobile menu (if needed)
    const mobileMenuButton = document.querySelector('.mobile-menu-button');
    if (mobileMenuButton) {
        mobileMenuButton.addEventListener('click', function() {
            document.querySelector('nav').classList.toggle('show');
        });
    }
});