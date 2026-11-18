import java.net.URI
import java.util.Base64

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
}

val api = providers.gradleProperty("API_BASE_URL").orElse("http://triproof.local/api/v1/").get()
val key =
    providers
        .gradleProperty("TRIPROOF_PUBLIC_KEY")
        .orElse("ImXhUWzqV+BrGs29mucsZGOsLV8CuMEA2BvgogWxEJQ=")
        .get()
val origin = URI(api)
val debugVhost = providers.gradleProperty("DEBUG_VHOST").orElse("").get()

require(debugVhost.isEmpty() || debugVhost.matches(Regex("[a-zA-Z0-9.-]+(:[0-9]{1,5})?")))

require(
    origin.scheme in setOf("https", "http") &&
        origin.host != null &&
        origin.userInfo == null &&
        origin.query == null &&
        origin.fragment == null &&
        api.endsWith("/")
) {
    "Invalid API_BASE_URL"
}

require(Base64.getDecoder().decode(key).size == 32) { "Expected raw Base64 Ed25519 public key" }

android {
    namespace = "ai.triproof.scanner"
    compileSdk = 35
    defaultConfig {
        applicationId = "ai.triproof.scanner"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "API_BASE_URL", "\"$api\"")
        buildConfigField("String", "PUBLIC_KEY", "\"$key\"")
    }
    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            manifestPlaceholders["cleartext"] = true
            buildConfigField("String", "DEBUG_VHOST", "\"$debugVhost\"")
        }
        release {
            manifestPlaceholders["cleartext"] = false
            buildConfigField("String", "DEBUG_VHOST", "\"\"")
            isMinifyEnabled = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }
    buildFeatures {
        viewBinding = true
        buildConfig = true
    }
    // Multi-release JVM/OSGi manifests are not used by Android's class loader.
    packaging.resources.excludes += "META-INF/versions/**/OSGI-INF/MANIFEST.MF"
    testOptions.managedDevices.devices {
        create<com.android.build.api.dsl.ManagedVirtualDevice>("scannerApi35") {
            device = "Pixel 2"
            apiLevel = 35
            systemImageSource = "aosp-atd"
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

kotlin { jvmToolchain(17) }

ksp { arg("room.schemaLocation", "$projectDir/schemas") }

dependencies {
    implementation(libs.core)
    implementation(libs.appcompat)
    implementation(libs.fragment)
    implementation(libs.activity)
    implementation(libs.material)
    implementation(libs.constraint)
    implementation(libs.recycler)
    implementation(libs.lifecycle.runtime)
    implementation(libs.lifecycle.viewmodel)
    implementation(libs.coroutines)
    implementation(libs.room.runtime)
    implementation(libs.room.ktx)
    ksp(libs.room.compiler)
    implementation(libs.work)
    implementation(libs.security)
    implementation(libs.tink)
    implementation(libs.bcprov)
    implementation(libs.camera.camera2)
    implementation(libs.camera.lifecycle)
    implementation(libs.camera.view)
    implementation(libs.ml.barcode)
    implementation(libs.ml.text)
    implementation(libs.retrofit.client)
    implementation(libs.retrofit.json)
    implementation(libs.json)
    implementation(libs.okhttp)
    implementation(libs.zxing)
    testImplementation(libs.junit)
    androidTestImplementation(libs.android.junit)
    androidTestImplementation(libs.android.runner)
    androidTestImplementation(libs.mockwebserver)
    androidTestImplementation(libs.espresso)
}
