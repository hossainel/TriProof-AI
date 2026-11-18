package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.databinding.ActivityLoginBinding
import android.content.Intent
import android.os.Bundle
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.isVisible
import kotlinx.coroutines.flow.receiveAsFlow

class LoginActivity : AppCompatActivity() {
    private val model: LoginModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val b = ActivityLoginBinding.inflate(layoutInflater)
        content(b.root)
        b.submit.setOnClickListener {
            val email = b.email.text.toString()
            val password = b.password.text.toString()
            if (email.isBlank() || password.isBlank()) toast(R.string.credentials_required)
            else model.submit(email, password)
        }
        b.guest.setOnClickListener { model.submit(null) }
        b.language.setOnClickListener { toggleLanguage() }
        watch(model.messages.receiveAsFlow(), ::toast)
        watch(model.busy) {
            b.submit.isEnabled = !it
            b.guest.isEnabled = !it
            b.busy.isVisible = it
        }
        watch(model.ready) {
            if (it) {
                startActivity(
                    Intent(this, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                )
                finish()
            }
        }
        if (!intent.getBooleanExtra("sign_in", false)) model.resumeSession()
    }
}
