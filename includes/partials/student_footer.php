</main>
  </div>
</div>
<nav class="nb-tabbar">
  <div class="nb-tabbar__inner">
    <?php foreach ($__tabs as [$__href, $__label, $__icon]): ?>
      <a href="<?= e($__href) ?>" class="<?= $__current === $__href ? 'is-active' : '' ?>"><?= nb_icon($__icon) ?><span><?= e($__label) ?></span></a>
    <?php endforeach; ?>
  </div>
</nav>
<script src="<?= asset_url('js/main.js') ?>"></script>
<script src="<?= asset_url('js/student.js') ?>"></script>
</body>
</html>
