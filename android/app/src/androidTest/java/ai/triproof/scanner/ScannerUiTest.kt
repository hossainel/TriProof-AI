package ai.triproof.scanner

import ai.triproof.scanner.ui.MainActivity
import ai.triproof.scanner.scanner.Demo
import android.Manifest
import android.os.Build
import androidx.test.core.app.ActivityScenario
import androidx.test.espresso.Espresso.onView
import androidx.test.espresso.action.ViewActions.*
import androidx.test.espresso.assertion.ViewAssertions.matches
import androidx.test.espresso.matcher.ViewMatchers.*
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import org.hamcrest.Matchers.not
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class ScannerUiTest {
    @Before fun allowNotifications() {
        val test = InstrumentationRegistry.getInstrumentation()
        if (Build.VERSION.SDK_INT >= 33)
            test.uiAutomation.grantRuntimePermission(
                test.targetContext.packageName,
                Manifest.permission.POST_NOTIFICATIONS,
            )
    }

    @Test
    fun opensOfflineSimulatorAndShowsActionableMismatch() {
        ActivityScenario.launch(MainActivity::class.java).use {
            onView(withId(R.id.demo)).perform(scrollTo(), click())
            onView(withId(R.id.forged)).perform(click())
            onView(withId(R.id.verdict)).check(matches(withText(R.string.fake)))
            onView(withId(R.id.close)).perform(click())
            onView(withId(R.id.navHistory)).perform(click())
            onView(withId(R.id.search)).check(matches(isDisplayed()))
            onView(withId(R.id.navAlerts)).perform(click())
            onView(withId(R.id.markRead)).check(matches(isDisplayed()))
        }
    }

    @Test fun banglaSwitchPreservesOuterAndRequiresSealConfirmation() {
        val inst = InstrumentationRegistry.getInstrumentation()
        val original = AppCompatDelegate.getApplicationLocales()
        try {
            inst.runOnMainSync { AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags("en")) }
            ActivityScenario.launch(MainActivity::class.java).use {
                onView(withId(R.id.manual)).perform(scrollTo(), click())
                onView(withId(R.id.input)).perform(replaceText(Demo.OUTER), closeSoftKeyboard())
                onView(withId(android.R.id.button1)).perform(click())
                onView(withId(R.id.manual)).check(matches(not(isEnabled())))
                onView(withId(R.id.language)).perform(click())
                onView(withId(R.id.language)).check(matches(withText("EN")))
                onView(withId(R.id.outer)).check(matches(withText(Demo.OUTER)))
                onView(withId(R.id.intact)).perform(scrollTo(), click())
                onView(withId(R.id.manual)).check(matches(isEnabled()))
                val bn = inst.targetContext.createConfigurationContext(android.content.res.Configuration(inst.targetContext.resources.configuration).apply { setLocale(java.util.Locale.forLanguageTag("bn")) })
                onView(withId(R.id.step)).check(matches(withText(bn.getString(R.string.step_inner))))
            }
        } finally { inst.runOnMainSync { AppCompatDelegate.setApplicationLocales(original) } }
    }
}
