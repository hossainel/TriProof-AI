package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.databinding.ActivityMainBinding
import ai.triproof.scanner.util.bangla
import android.Manifest
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.isVisible

class MainActivity : AppCompatActivity() {
    private val model: MainModel by viewModels()
    private lateinit var b: ActivityMainBinding
    private val permission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) {}

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityMainBinding.inflate(layoutInflater)
        content(b.root)
        b.language.setOnClickListener { toggleLanguage() }
        b.account.setOnClickListener {
            startActivity(Intent(this, LoginActivity::class.java).putExtra("sign_in", true))
        }
        b.navigation.setOnItemSelectedListener { item ->
            val tag = "tab:${item.itemId}"
            if (supportFragmentManager.findFragmentById(R.id.content)?.tag != tag) {
                val fragment =
                    when (item.itemId) {
                        R.id.navHistory -> HistoryFragment()
                        R.id.navAlerts -> NotificationsFragment()
                        else -> ScannerFragment()
                    }
                supportFragmentManager
                    .beginTransaction()
                    .setReorderingAllowed(true)
                    .replace(R.id.content, fragment, tag)
                    .commit()
            }
            true
        }
        if (savedInstanceState == null)
            b.navigation.selectedItemId =
                if (intent.getBooleanExtra("inbox", false)) R.id.navAlerts else R.id.navScanner
        watch(model.connection) { c ->
            b.status.setText(
                when {
                    !c.connected -> R.string.offline_ready
                    !c.hasToken -> R.string.online_guest
                    c.synced -> R.string.online_synced
                    else -> R.string.online_pending
                }
            )
            b.status.tint(if (c.connected) R.color.blue else R.color.good)
            b.account.isVisible = !c.hasToken
        }
        watch(model.unread) {
            if (it == 0) b.navigation.removeBadge(R.id.navAlerts)
            else b.navigation.getOrCreateBadge(R.id.navAlerts).number = it
        }
        watch(model.banner) { row ->
            b.banner.isVisible = row != null
            if (row != null) {
                b.bannerMessage.text = if (bangla()) row.bn else row.en
                b.dismissBanner.setOnClickListener { model.dismiss(row) }
                b.bannerMessage.setOnClickListener { b.navigation.selectedItemId = R.id.navAlerts }
            }
        }
        val prefs = getSharedPreferences("prompts", MODE_PRIVATE)
        if (Build.VERSION.SDK_INT >= 33 && !prefs.getBoolean("notificationAsked", false)) {
            prefs.edit().putBoolean("notificationAsked", true).apply()
            permission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    override fun onStart() {
        super.onStart()
        model.foreground()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (intent.getBooleanExtra("inbox", false)) b.navigation.selectedItemId = R.id.navAlerts
    }
}
