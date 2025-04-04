// plugins/generic/calidadfecyt/js/calidadfecyt.js

document.addEventListener("DOMContentLoaded", function() {
  /**
   * Initializes the CalidadFECYT plugin functionality.
   * Sets up event listeners and updates the submission dropdown based on date range inputs.
   * Retries initialization if required DOM elements are not yet available.
   */
  function initializeCalidadFECYT() {
    const dateFromInput = document.getElementById("dateFrom");
    const dateToInput = document.getElementById("dateTo");
    const submissionSelect = document.getElementById("submission");

    if (!dateFromInput || !dateToInput || !submissionSelect) {
      setTimeout(initializeCalidadFECYT, 500);
      return;
    }

    function updateSubmissions() {
      const dateFrom = dateFromInput.value;
      const dateTo = dateToInput.value;

      fetch(window.fetchSubmissionsUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body:
          "dateFrom=" +
          encodeURIComponent(dateFrom) +
          "&dateTo=" +
          encodeURIComponent(dateTo),
      })
        .then((response) => {
          return response.json();
        })
        .then((data) => {
          submissionSelect.innerHTML = "";
          if (data.status && data.content && data.content.length > 0) {
            data.content.forEach((submission, index) => {
              const option = document.createElement("option");
              option.value = submission.id;
              option.textContent = submission.id + " - " + submission.title;
              submissionSelect.appendChild(option);
            });
          } else {
            const option = document.createElement("option");

            option.value = "";
            option.textContent = window.noSubmissionsMessage;
            submissionSelect.appendChild(option);
          }
        })
        .catch((error) => console.error("Fetch error:", error));
    }
    dateFromInput.addEventListener("change", function() {
      updateSubmissions();
    });
    dateToInput.addEventListener("change", function() {
      updateSubmissions();
    });

    updateSubmissions();
  }

  initializeCalidadFECYT();
});
