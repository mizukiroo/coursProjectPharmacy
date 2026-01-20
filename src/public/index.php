<?php include __DIR__ . '/header.php'; ?>
<style>
    /* чтобы белый .landing не перекрывал фон */
    .landing { background: transparent !important; }

    /* кладём картинку прямо в слой hero (он и так накрывает страницу) */
    .landing-hero-bg{
        opacity: 1 !important;
        background:
                linear-gradient(180deg, rgba(240,255,246,.80), rgba(220,245,232,.85)),
                url("images/home-bg.jpg") center/cover no-repeat;
    }

    /* на всякий случай пусть body тоже будет с фоном */
    body{
        background:
                linear-gradient(180deg, rgba(240,255,246,.88), rgba(220,245,232,.88)),
                url("images/home-bg.jpg") center/cover no-repeat fixed;
    }
</style>

<div class="landing">

    <!-- HERO -->
    <section class="landing-hero">
        <div class="landing-hero-bg"></div>

        <div class="container landing-hero-grid">
            <div class="landing-hero-left">
                <h1 class="landing-title">
                    Забронируйте льготные лекарства
                    <span>в удобной социальной аптеке</span>
                </h1>

                <p class="landing-subtitle">
                    Врач назначает препараты, система открывает к ним доступ,
                    а вы забираете лекарства в ближайшей социальной аптеке без очередей.
                </p>

                <div class="landing-cta-row">
                    <a href="howto.php" class="btn btn-primary landing-cta-main">
                        Как получить услугу
                    </a>
                    <a href="map.php" class="btn btn-ghost landing-cta-secondary">
                        Аптеки на карте
                    </a>
                </div>

                <div class="landing-bullets">
                    <div class="landing-bullet">
                        <span class="landing-bullet-icon">☘︎️</span>
                        Назначение и доступ только по рецепту врача
                    </div>
                    <div class="landing-bullet">
                        <span class="landing-bullet-icon">☘︎</span>
                        Социальные аптеки рядом с домом
                    </div>
                    <div class="landing-bullet">
                        <span class="landing-bullet-icon">☘︎</span>
                        Без цен — только льготные программы
                    </div>
                </div>
            </div>

            <div class="landing-hero-right">
                <!-- просто красивая иллюстрация, без пациента -->
                <div class="landing-hero-illustration">
                    <div class="landing-hero-circle landing-hero-circle--big"></div>
                    <div class="landing-hero-circle landing-hero-circle--small"></div>
                    <div class="landing-hero-caption">
                        Льготные препараты<br> по вашим рецептам
                    </div>
                </div>

                <div class="landing-stats">

                    <div class="landing-stat">
                        <div class="landing-stat-number">200+</div>
                        <div class="landing-stat-label">социальных аптек в системе</div>
                    </div>
                    <div class="landing-stat">
                        <div class="landing-stat-number">24/7</div>
                        <div class="landing-stat-label">доступ к вашим назначениям</div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- КАК ЭТО РАБОТАЕТ -->
    <section class="landing-section">
        <div class="container">
            <div class="landing-section-head">
                <h2>Как работает сервис</h2>
                <p>Прозрачный путь от назначения врача до выдачи льготных лекарств.</p>
            </div>

            <div class="landing-steps">
                <article class="landing-step">
                    <div class="landing-step-number">1</div>
                    <h3>Врач назначает лечение</h3>
                    <p>
                        Врач формирует электронный рецепт, выбирает необходимые препараты.
                        Рецепт сразу появляется в личном кабинете пациента.
                    </p>
                </article>

                <article class="landing-step">
                    <div class="landing-step-number">2</div>
                    <h3>Пациент бронирует лекарства</h3>
                    <p>
                        Пациент заходит на сайт, видит список доступных по рецепту препаратов,
                        выбирает социальную аптеку и отправляет бронь.
                    </p>
                </article>

                <article class="landing-step">
                    <div class="landing-step-number">3</div>
                    <h3>Аптекарь выдает препараты</h3>
                    <p>
                        Аптекарь получает заказ, собирает лекарства и отмечает «выдано» —
                        система фиксирует, кем и когда выдан заказ.
                    </p>
                </article>


            </div>
        </div>
    </section>

    <!-- БЛОК ПРО СОЦПАКЕТ -->
    <section class="landing-section landing-section-alt">
        <div class="container landing-info-grid">
            <div>
                <h2>Только социальные пакеты</h2>
                <p class="landing-text">
                    На сайте нет цен и корзины в привычном интернет-магазинном виде.
                    Мы работаем только с государственными программами льготного обеспечения.
                </p>
                <p class="landing-text">
                    В личном кабинете вы видите только те лекарства, которые назначил
                    ваш врач и которые доступны именно вам.
                </p>
            </div>
            <div class="landing-info-card">
                <div class="landing-info-title">Как попасть в программу</div>
                <ul class="landing-info-list">
                    <li>Подать заявление на участие через портал Госуслуг;</li>
                    <li>Закрепиться за поликлиникой и получить льготный статус;</li>
                    <li>Получить рецепт у врача и авторизоваться в системе аптеки.</li>
                </ul>
                <a href="howto.php" class="btn btn-primary btn-block">
                    Подробнее о получении услуги
                </a>
            </div>
        </div>
    </section>

</div>

<?php include __DIR__ . '/footer.php'; ?>
