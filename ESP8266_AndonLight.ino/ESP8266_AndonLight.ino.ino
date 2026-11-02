#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>

// ==========================================
// 1. CHANGE THESE TO MATCH YOUR WI-FI!
// ==========================================
const char* ssid = "AndroidAP7EBD";
const char* password = "";

// 2. CHANGE THIS TO YOUR SERVER'S IP ADDRESS
const char* apiUrl = "http://192.168.188.166/Real%20Time%20Work%20Order/backend/andon_api.php";
// ==========================================

// Define the 4 pins for your 4 components
const int RED_PIN = D1;
const int YELLOW_PIN = D2;
const int GREEN_PIN = D3;
const int BUZZER_PIN = D5;

// Timers for polling the server (every 3 seconds)
unsigned long previousPollMillis = 0;
const long pollInterval = 3000; 

// Variables for the Beeping Alarm
unsigned long previousAlarmMillis = 0;
long buzzerInterval = 0; // 0 means silent. 1000 = slow beep. 250 = fast beep.
bool alarmState = false; 
String currentStatus = "OFF";

void setup() {
  Serial.begin(115200);
  
  pinMode(RED_PIN, OUTPUT);
  pinMode(YELLOW_PIN, OUTPUT);
  pinMode(GREEN_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  turnOffAll();

  Serial.println();
  Serial.print("Connecting to Wi-Fi: ");
  WiFi.begin(ssid, password);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }

  Serial.println("\nWi-Fi connected!");
  Serial.print("IP Address: ");
  Serial.println(WiFi.localIP());
}

void loop() {
  unsigned long currentMillis = millis();

  // ========================================================
  // TASK 1: THE DYNAMIC ALARM (Runs constantly)
  // ========================================================
  if (buzzerInterval > 0) {
    if (currentMillis - previousAlarmMillis >= buzzerInterval) {
      previousAlarmMillis = currentMillis;
      alarmState = !alarmState; 
      
      // Toggle the buzzer
      digitalWrite(BUZZER_PIN, alarmState ? HIGH : LOW);
      
      // Flash the corresponding LED in sync with the buzzer
      if (currentStatus == "YELLOW") {
        digitalWrite(YELLOW_PIN, alarmState ? HIGH : LOW);
      } else if (currentStatus == "RED") {
        digitalWrite(RED_PIN, alarmState ? HIGH : LOW);
      }
    }
  } else {
    // Force buzzer off if we are GREEN or OFF
    digitalWrite(BUZZER_PIN, LOW);
  }

  // ========================================================
  // TASK 2: POLL THE SERVER (Runs every 3 seconds)
  // ========================================================
  if (currentMillis - previousPollMillis >= pollInterval) {
    previousPollMillis = currentMillis;

    if (WiFi.status() == WL_CONNECTED) {
      WiFiClient client;
      HTTPClient http;

      http.begin(client, apiUrl);
      int httpResponseCode = http.GET();

      if (httpResponseCode > 0) {
        String payload = http.getString();
        payload.trim(); 
        Serial.print("Server status: ");
        Serial.println(payload);
        
        currentStatus = payload;

        // --- TRAFFIC LIGHT & BUZZER LOGIC ---
        if (payload == "GREEN") {
          digitalWrite(GREEN_PIN, HIGH);
          digitalWrite(YELLOW_PIN, LOW);
          digitalWrite(RED_PIN, LOW);
          buzzerInterval = 0; // SILENT
        } 
        else if (payload == "YELLOW") {
          digitalWrite(GREEN_PIN, LOW);
          digitalWrite(RED_PIN, LOW);
          buzzerInterval = 1000; // SLOW BEEP & FLASH (Every 1 second)
        } 
        else if (payload == "RED") {
          digitalWrite(GREEN_PIN, LOW);
          digitalWrite(YELLOW_PIN, LOW);
          buzzerInterval = 250; // FAST BEEP & FLASH (Every 1/4 second)
        }
        else {
          turnOffAll();
        }
      } else {
        Serial.println("Server disconnected!");
        turnOffAll();
      }
      http.end(); 
    }
  }
}

void turnOffAll() {
  digitalWrite(GREEN_PIN, LOW);
  digitalWrite(YELLOW_PIN, LOW);
  digitalWrite(RED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);
  buzzerInterval = 0;
  currentStatus = "OFF";
}
