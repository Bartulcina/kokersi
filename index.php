<!DOCTYPE html>
<html lang="en">

<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

?>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>KOKERSI 3 MULTIDIMENZIONALNA KOMUNIKACIJA</title>

    <link rel="stylesheet" href="style.css">

</head>

<body>

    <div class="grid-container">

        <div class="top">

            <img src="slike/diste.png" alt="cetnici">

            <?php include "navbar.php"; ?>

        </div>


        <div class="column1">

            <h1>
                Evala kok,
                <?php echo htmlspecialchars($_SESSION["username"]); ?>
            </h1>


            <form id="chatForm">

                <input
                    type="text"
                    id="message"
                    placeholder="message"
                    required
                    autocomplete="off"
                >

                <button type="submit">
                    Send
                </button>

            </form>


            <pre id="messages"></pre>

        </div>


        <div class="column2">

        </div>


        <div class="bottom">

            <h2>
                @banklan trademark
            </h2>

        </div>

    </div>


    <script>

        const form = document.querySelector("#chatForm");
        const messageInput = document.querySelector("#message");
        const messages = document.querySelector("#messages");


        form.addEventListener("submit", function(event) {

            event.preventDefault();

            if (messageInput.value.trim() === "") {
                return;
            }


            const data = new FormData();

            data.append(
                "message",
                messageInput.value
            );


            fetch("send.php", {

                method: "POST",
                body: data

            });


            messageInput.value = "";

        });


        function loadMessages() {

            fetch("messages.php")

                .then(response => response.text())

                .then(data => {

                    messages.textContent = data;

                });

        }


        setInterval(loadMessages, 500);

        loadMessages();

    </script>

</body>

</html>