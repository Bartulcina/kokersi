
<?php
require_once __DIR__ . "/auth.php";
?>

<!DOCTYPE html>
<html lang="en">

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
                <?php if (loggedIn()): ?>

                    Evala kok,
                    <?= htmlspecialchars($_SESSION["username"] ?? "korisnik") ?>

                <?php else: ?>

                    Evala kok, goste!

                <?php endif; ?>
            </h1>


            <?php if (loggedIn()): ?>

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

            <?php else: ?>

                <div class="panel">

                    <p>
                        Logiraj se za slanje poruka.
                    </p>

                    <a href="login.php" class="nav-btn btn-login">
                        LOGIN
                    </a>

                </div>

            <?php endif; ?>


            <pre id="messages"></pre>

        </div>


        <div class="column2">

            <img
                src="slike/bartul.png"
                alt="carobnjak vas nabildani"
            >

            <img
                src="slike/bartul.gif"
                alt=""
            >

        </div>


        <div class="bottom">

            <h2>
                @banklan trademark THIS IS SATIRE AND NOTHING ILLEGAL IS HERE!!!
            </h2>

        </div>

    </div>


    <script>

        const form = document.querySelector("#chatForm");
        const messageInput = document.querySelector("#message");
        const messages = document.querySelector("#messages");


        // only logged-in users can send messages
        if (form) {

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

                })
                .then(response => {

                    if (!response.ok) {
                        throw new Error("Failed to send message");
                    }

                    messageInput.value = "";

                    loadMessages();

                })
                .catch(error => {
                    console.error(error);
                });

            });

        }


        // everyone can read messages
        function loadMessages() {

            fetch("messages.php", { cache: "no-store" })

                .then(response => {

                    if (!response.ok) {
                        throw new Error("Failed to load messages");
                    }

                    return response.text();

                })

                .then(data => {

                    messages.textContent = data;

                })

                .catch(error => {
                    console.error(error);
                });

        }


        setInterval(loadMessages, 500);

        loadMessages();

    </script>

</body>

</html>
